<?php

include '../config/db.php';
session_start();

date_default_timezone_set('Asia/Manila');


// =====================================================
// CHECK LOGIN
// =====================================================

if (!isset($_SESSION['user'])) {

    header("Location: ../index.php");
    exit();

}

// =====================================================
// ENSURE CLASS SCHEDULE COLUMN EXISTS
// This keeps the registration page working even if the
// database migration has not yet been run manually.
// =====================================================
$classScheduleColumnExists = false;
$checkScheduleColumn = $conn->query("SHOW COLUMNS FROM students LIKE 'class_schedule'");
if ($checkScheduleColumn && $checkScheduleColumn->num_rows > 0) {
    $classScheduleColumnExists = true;
} else {
    // TEXT is intentionally used instead of JSON for compatibility.
    $conn->query("ALTER TABLE students ADD COLUMN class_schedule TEXT NULL AFTER subject");
    $checkScheduleColumn = $conn->query("SHOW COLUMNS FROM students LIKE 'class_schedule'");
    if ($checkScheduleColumn && $checkScheduleColumn->num_rows > 0) {
        $classScheduleColumnExists = true;
    }
}


// =====================================================
// NORMALIZE RFID
// =====================================================

function normalizeRFID($rfid)
{
    $rfid = strtoupper(trim($rfid));

    // Remove colon
    $rfid = str_replace(':', '', $rfid);

    // Remove dash
    $rfid = str_replace('-', '', $rfid);

    // Remove spaces
    $rfid = str_replace(' ', '', $rfid);

    return $rfid;
}


// =====================================================
// BSIT SUBJECTS
// =====================================================
// These are the subjects available in the registration
// dropdown. You can add/remove subjects here later.

$bsitSubjects = [

    // =================================================
    // GENERAL EDUCATION
    // =================================================

    "Understanding the Self",

    "Readings in Philippine History",

    "The Contemporary World",

    "Mathematics in the Modern World",

    "Purposive Communication",

    "Art Appreciation",

    "Science, Technology and Society",

    "Ethics",

    "Life and Works of Rizal",

    "Peace Education",

    "Gender and Society",


    // =================================================
    // BSIT / COMPUTING FOUNDATION
    // =================================================

    "Introduction to Computing",

    "Computer Programming 1",

    "Computer Programming 2",

    "Data Structures and Algorithms",

    "Information Management",

    "Applications Development and Emerging Technologies",

    "Discrete Mathematics",

    "Statistical Concepts",


    // =================================================
    // PROFESSIONAL BSIT SUBJECTS
    // =================================================

    "Introduction to Human Computer Interaction 1",

    "Introduction to Human Computer Interaction 2",

    "Mobile Applications",

    "Networking 1",

    "Networking 2",

    "Integrative Programming and Technologies 1",

    "Integrative Programming and Technologies 2",

    "Mobile and Pervasive Technologies",

    "Web Systems and Technologies 1",

    "Web Systems and Technologies 2",

    "Advanced Database Systems",

    "Systems Integration and Architecture",

    "System Analysis and Design of Algorithms",

    "Social and Professional Issues",

    "Information Assurance and Security 1",

    "Information Assurance and Security 2",

    "Qualitative Methods",

    "Software Engineering",

    "Web and Mobile User Experience Design",

    "Cloud Computing and Technologies",

    "Developing Multiplatform Mobile Application",

    "Systems Administration and Maintenance",

    "Special Topics for IT",

    "Capstone Project 1",

    "Capstone Project 2",

    "Practicum",


    // =================================================
    // ELECTIVES
    // =================================================

    "Platform Technologies",

    "Object-Oriented Programming",


    // =================================================
    // PHYSICAL EDUCATION
    // =================================================

    "Physical Activities Toward Health and Fitness 1",

    "Physical Activities Toward Health and Fitness 2",

    "Physical Activities Toward Health and Fitness 3",

    "Physical Activities Toward Health and Fitness 4",


    // =================================================
    // NSTP
    // =================================================

    "CWTS / ROTC / LTS 1",

    "CWTS / ROTC / LTS 2",


    // =================================================
    // OTHER / INSTITUTIONAL
    // =================================================

    "Foreign Language",

    "Green Technology Education"

];


// =====================================================
// REMOVE DUPLICATES
// =====================================================

$bsitSubjects = array_values(
    array_unique($bsitSubjects)
);


// =====================================================
// SORT SUBJECTS
// =====================================================

sort(
    $bsitSubjects,
    SORT_NATURAL | SORT_FLAG_CASE
);


// =====================================================
// DEFAULT VARIABLES
// =====================================================

$message = '';

$messageType = '';


// =====================================================
// SAVE STUDENT
// =====================================================

if (isset($_POST['save'])) {


    // -------------------------------------------------
    // GET FORM DATA
    // -------------------------------------------------

    $student_No = trim(
        $_POST['student_No'] ?? ''
    );


    $first_name = trim(
        $_POST['first_name'] ?? ''
    );


    $middle_name = trim(
        $_POST['middle_name'] ?? ''
    );


    $last_name = trim(
        $_POST['last_name'] ?? ''
    );


    $extension_name = trim(
        $_POST['extension_name'] ?? ''
    );


    $rfid = normalizeRFID(
        $_POST['rfid'] ?? ''
    );


    $gender = trim(
        $_POST['gender'] ?? ''
    );


    $year_level = trim(
        $_POST['year_level'] ?? ''
    );


    $section = trim(
        $_POST['section'] ?? ''
    );


    // -------------------------------------------------
    // GET MULTIPLE SUBJECTS
    // -------------------------------------------------

    $selectedSubjects =
        $_POST['subjects'] ?? [];


    // Make sure subjects is an array
    if (!is_array($selectedSubjects)) {

        $selectedSubjects = [];

    }


    // Remove empty values
    $selectedSubjects = array_filter(
        array_map(
            'trim',
            $selectedSubjects
        ),
        function ($value) {

            return $value !== '';

        }
    );


    // Remove duplicates
    $selectedSubjects =
        array_values(
            array_unique(
                $selectedSubjects
            )
        );


    // -------------------------------------------------
    // GET CLASS SCHEDULE
    // -------------------------------------------------
    $scheduleInput = $_POST['schedule'] ?? [];
    if (!is_array($scheduleInput)) { $scheduleInput = []; }

    $allowedDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $classSchedule = [];
    $scheduleError = '';

    foreach ($allowedDays as $day) {
        $dayData = $scheduleInput[$day] ?? [];
        if (!is_array($dayData)) { continue; }
        
        // Prioritize native time input if filled, fallback to dropdown select
        $timeInRaw = trim((string)($dayData['time_in_input'] ?? ''));
        if ($timeInRaw === '') {
            $timeInRaw = trim((string)($dayData['time_in'] ?? ''));
        }
        
        $timeOutRaw = trim((string)($dayData['time_out_input'] ?? ''));
        if ($timeOutRaw === '') {
            $timeOutRaw = trim((string)($dayData['time_out'] ?? ''));
        }

        if ($timeInRaw === '' && $timeOutRaw === '') { continue; }
        
        if ($timeInRaw === '' || $timeOutRaw === '') {
            $scheduleError = $day . ': please select/enter both Time In and Time Out.';
            break;
        }

        // Standardize format HH:MM:SS
        $timeIn = date('H:i:s', strtotime($timeInRaw));
        $timeOut = date('H:i:s', strtotime($timeOutRaw));

        if (!$timeIn || !$timeOut) {
            $scheduleError = $day . ': invalid time selected.';
            break;
        }

        // All time ranges accepted (including overnight/equal ranges)
        $classSchedule[] = ['day'=>$day, 'time_in'=>$timeIn, 'time_out'=>$timeOut];
    }


    // =================================================
    // VALIDATE REQUIRED FIELDS
    // =================================================

    if (
        $student_No === '' ||
        $first_name === '' ||
        $last_name === '' ||
        $rfid === '' ||
        $gender === '' ||
        $year_level === '' ||
        $section === ''
    ) {

        $message =
            "Please complete all required fields.";

        $messageType =
            "danger";

    }


    elseif ($scheduleError !== '') {
        $message = $scheduleError;
        $messageType = 'danger';
    }


    // =================================================
    // VALIDATE SUBJECT
    // =================================================

    elseif (
        count($selectedSubjects) === 0
    ) {

        $message =
            "Please select at least one subject.";

        $messageType =
            "danger";

    }


    // =================================================
    // VALIDATE SELECTED SUBJECTS
    // =================================================

    elseif (
        count(
            array_diff(
                $selectedSubjects,
                $bsitSubjects
            )
        ) > 0
    ) {

        $message =
            "One or more selected subjects are invalid.";

        $messageType =
            "danger";

    }


    // =================================================
    // VALIDATE GENDER
    // =================================================

    elseif (
        $gender !== 'Male' &&
        $gender !== 'Female'
    ) {

        $message =
            "Invalid gender selected.";

        $messageType =
            "danger";

    }


    // =================================================
    // VALIDATE YEAR LEVEL
    // =================================================

    elseif (
        !in_array(
            $year_level,
            [
                '1st Year',
                '2nd Year',
                '3rd Year',
                '4th Year'
            ],
            true
        )
    ) {

        $message =
            "Invalid year level.";

        $messageType =
            "danger";

    }


    // =================================================
    // VALIDATE SECTION / YEAR LEVEL MATCH
    // =================================================

    elseif (
        preg_match(
            '/(?:^|[^0-9])([1-4])(?:[^0-9]|$)/',
            $section,
            $sectionYearMatch
        ) &&
        (
            (int)$sectionYearMatch[1] !==
            (int)substr($year_level, 0, 1)
        )
    ) {

        $message =
            "Section does not match the selected year level.";

        $messageType =
            "danger";

    }


    // =================================================
    // VALIDATE SECTION LENGTH
    // =================================================

    elseif (
        strlen($section) > 100
    ) {

        $message =
            "Section is too long.";

        $messageType =
            "danger";

    }


    // =================================================
    // VALIDATE RFID FORMAT
    // =================================================

    elseif (
        !preg_match(
            '/^[0-9A-F]+$/',
            $rfid
        )
    ) {

        $message =
            "Invalid RFID format. Use the RFID UID from the card.";

        $messageType =
            "danger";

    }


    // =================================================
    // CHECK DUPLICATE STUDENT NUMBER
    // =================================================

    else {

        $duplicateStudentNo = $conn->prepare("
            SELECT
                stud_id,
                student_No,
                first_name,
                last_Name
            FROM students
            WHERE TRIM(student_No) = ?
            LIMIT 1
        ");

        if (!$duplicateStudentNo) {
            $message =
                "Database error: " .
                $conn->error;
            $messageType = "danger";
        } else {
            $duplicateStudentNo->bind_param("s", $student_No);
            $duplicateStudentNo->execute();
            $studentNoResult = $duplicateStudentNo->get_result();

            if ($studentNoResult->num_rows > 0) {
                $existingStudent = $studentNoResult->fetch_assoc();
                $existingName = trim(
                    ($existingStudent['first_name'] ?? '') . ' ' .
                    ($existingStudent['last_Name'] ?? '')
                );

                $message =
                    "Student Number already exists for " .
                    $existingName .
                    " (Student No: " .
                    $existingStudent['student_No'] .
                    "). No duplicate student record was created.";
                $messageType = "warning";
            } else {

                // =================================================
                // CHECK DUPLICATE RFID
                // =================================================

                $duplicate = $conn->prepare("
            SELECT
                stud_id,
                student_No,
                first_name,
                last_Name,
                student_RFID_Code
            FROM students
            WHERE REPLACE(
                REPLACE(
                    REPLACE(
                        UPPER(
                            TRIM(student_RFID_Code)
                        ),
                        ':',
                        ''
                    ),
                    '-',
                    ''
                ),
                ' ',
                ''
            ) = ?
            LIMIT 1
        ");


        // ---------------------------------------------
        // CHECK PREPARE ERROR
        // ---------------------------------------------

        if (!$duplicate) {

            $message =
                "Database error: " .
                $conn->error;

            $messageType =
                "danger";

        }

        else {

            $duplicate->bind_param(
                "s",
                $rfid
            );


            $duplicate->execute();


            $duplicateResult =
                $duplicate->get_result();


            // =========================================
            // RFID ALREADY EXISTS
            // =========================================

            if (
                $duplicateResult->num_rows > 0
            ) {

                $existing =
                    $duplicateResult->fetch_assoc();


                $message =
                    "RFID already registered to " .
                    $existing['first_name'] .
                    " " .
                    $existing['last_Name'] .
                    " (Student No: " .
                    $existing['student_No'] .
                    ").";


                $messageType =
                    "warning";

            }


            // =========================================
            // SAVE NEW STUDENT
            // =========================================

            else {

                // -------------------------------------
                // GENDER
                // -------------------------------------

                $is_Male =
                    ($gender === "Male")
                    ? 1
                    : 0;


                $is_Female =
                    ($gender === "Female")
                    ? 1
                    : 0;


                // -------------------------------------
                // CONVERT SUBJECTS TO JSON
                // -------------------------------------

                $subjectJSON =
                    json_encode(
                        $selectedSubjects,
                        JSON_UNESCAPED_UNICODE
                    );


                // -------------------------------------
                // CHECK JSON
                // -------------------------------------

                if ($subjectJSON === false) {

                    $message =
                        "Unable to save selected subjects.";

                    $messageType =
                        "danger";

                }

                else {


                    // =================================
                    // INSERT STUDENT
                    // =================================

                    $hasScheduleColumn = false;
                    $columnCheck = $conn->query("SHOW COLUMNS FROM students LIKE 'class_schedule'");
                    if ($columnCheck && $columnCheck->num_rows > 0) {
                        $hasScheduleColumn = true;
                    }

                    $scheduleJSON = json_encode(
                        $classSchedule,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                    if ($scheduleJSON === false) { $scheduleJSON = '[]'; }

                    if ($hasScheduleColumn) {
                        $insert = $conn->prepare("
                            INSERT INTO students
                            (student_No, first_name, middle_Name, last_Name, extension_name,
                             student_RFID_Code, is_Male, is_Female, year_level, section, subject, class_schedule)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                    } else {
                        $insert = $conn->prepare("
                            INSERT INTO students
                            (student_No, first_name, middle_Name, last_Name, extension_name,
                             student_RFID_Code, is_Male, is_Female, year_level, section, subject)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                    }


                    // ---------------------------------
                    // PREPARE ERROR
                    // ---------------------------------

                    if (!$insert) {

                        $message =
                            "Database error: " .
                            $conn->error;

                        $messageType =
                            "danger";

                    }

                    else {


                        // -----------------------------
                        // BIND PARAMETERS
                        // -----------------------------

                        if ($hasScheduleColumn) {
                            $insert->bind_param(
                                "ssssssiiisss",
                                $student_No, $first_name, $middle_name, $last_name,
                                $extension_name, $rfid, $is_Male, $is_Female,
                                $year_level, $section, $subjectJSON, $scheduleJSON
                            );
                        } else {
                            $insert->bind_param(
                                "ssssssiiiss",
                                $student_No, $first_name, $middle_name, $last_name,
                                $extension_name, $rfid, $is_Male, $is_Female,
                                $year_level, $section, $subjectJSON
                            );
                        }


                        // -----------------------------
                        // EXECUTE INSERT
                        // -----------------------------

                        if ($insert->execute()) {


                            // =========================
                            // SYSTEM LOG
                            // =========================

                            $user_id =
                                $_SESSION['user']['user_id']
                                ?? '';


                            $username =
                                $_SESSION['user']['first_name']
                                ?? 'Unknown';


                            $activity =
                                "Added new student: " .
                                $first_name .
                                " " .
                                $last_name .
                                " | Student No: " .
                                $student_No .
                                " | Year Level: " .
                                $year_level .
                                " | Section: " .
                                $section .
                                " | Subjects: " .
                                implode(
                                    ', ',
                                    $selectedSubjects
                                ) .
                                " | Schedule: " .
                                count($classSchedule) .
                                " day(s)" .
                                " | RFID: " .
                                $rfid;


                            $ip =
                                $_SERVER['REMOTE_ADDR']
                                ?? 'UNKNOWN';


                            $logDate =
                                date('Y-m-d');


                            $logTime =
                                date('H:i:s');


                            // -------------------------
                            // INSERT LOG
                            // -------------------------

                            $log = $conn->prepare("
                                INSERT INTO system_logs
                                (
                                    user_id,
                                    username,
                                    activity,
                                    ip_address,
                                    log_date,
                                    log_time
                                )
                                VALUES
                                (
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?
                                )
                            ");


                            if ($log) {

                                $log->bind_param(
                                    "ssssss",
                                    $user_id,
                                    $username,
                                    $activity,
                                    $ip,
                                    $logDate,
                                    $logTime
                                );


                                $log->execute();


                                $log->close();

                            }


                            // =========================
                            // SUCCESS
                            // =========================

                            $message =
                                "Student Added Successfully! " .
                                count($selectedSubjects) .
                                " subject(s) registered.";


                            $messageType =
                                "success";


                            // -------------------------
                            // CLEAR FORM
                            // -------------------------

                            $_POST = [];

                            $selectedSubjects = [];

                        }

                        else {

                            $message =
                                "Error adding student: " .
                                $insert->error;

                            $messageType =
                                "danger";

                        }


                        $insert->close();

                    }

                }

            }


            $duplicate->close();

                }

            }

            $duplicateStudentNo->close();

        }

    }

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>NEUST Add Student</title>


<!-- =====================================================
     BOOTSTRAP
===================================================== -->

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet"
>


<!-- =====================================================
     BOOTSTRAP ICONS
===================================================== -->

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"
    rel="stylesheet"
>


<style>

/* =====================================================
   GLOBAL
===================================================== */

* {

    box-sizing:
        border-box;

}


body {

    margin: 0;

    min-height: 100vh;

    font-family:
        'Segoe UI',
        Arial,
        sans-serif;

    background:
        linear-gradient(
            135deg,
            #001f3f,
            #0056b3,
            #38bdf8
        );

}


/* =====================================================
   SIDEBAR
===================================================== */

.sidebar {

    width:
        260px;

    height:
        100vh;

    position:
        fixed;

    left:
        0;

    top:
        0;

    background:
        rgba(
            15,
            23,
            42,
            .90
        );

    color:
        white;

    padding:
        25px 15px;

    overflow-y:
        auto;

    z-index:
        1000;

}


.logo {

    width:
        85px;

    height:
        85px;

    background:
        white;

    border-radius:
        50%;

    padding:
        8px;

    object-fit:
        contain;

    display:
        block;

    margin:
        auto;

}


.sidebar h4 {

    text-align:
        center;

    font-weight:
        800;

    margin:
        15px
        0
        30px;

}


.sidebar a {

    display:
        flex;

    align-items:
        center;

    gap:
        12px;

    padding:
        13px
        18px;

    margin:
        8px
        0;

    border-radius:
        14px;

    color:
        #dbeafe;

    text-decoration:
        none;

    transition:
        .2s ease;

}


.sidebar a:hover,
.sidebar .active {

    background:
        linear-gradient(
            90deg,
            #2563eb,
            #38bdf8
        );

    color:
        white;

}


.sidebar a i {

    font-size:
        18px;

}


/* =====================================================
   TOPBAR
===================================================== */

.topbar {

    margin-left:
        260px;

    height:
        75px;

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    padding:
        0 35px;

    color:
        white;

    background:
        rgba(
            255,
            255,
            255,
            .15
        );

    backdrop-filter:
        blur(15px);

}


/* =====================================================
   CONTENT
===================================================== */

.content {

    margin-left:
        260px;

    padding:
        40px;

    min-height:
        calc(
            100vh - 75px
        );

}


/* =====================================================
   CARD
===================================================== */

.card-box {

    max-width:
        900px;

    margin:
        auto;

    background:
        rgba(
            255,
            255,
            255,
            .18
        );

    backdrop-filter:
        blur(18px);

    padding:
        35px;

    border-radius:
        25px;

    box-shadow:
        0 25px 60px
        rgba(
            0,
            0,
            0,
            .25
        );

    color:
        white;

}


.card-box h3 {

    font-weight:
        800;

}


/* =====================================================
   FORM
===================================================== */

.form-label {

    font-weight:
        600;

    color:
        white;

}


.form-control,
.form-select {

    min-height:
        50px;

    border-radius:
        15px;

    border:
        none;

    padding:
        10px
        15px;

    box-shadow:
        none;

}


.form-control:focus,
.form-select:focus {

    border:
        none;

    box-shadow:
        0 0 0 3px
        rgba(
            255,
            255,
            255,
            .25
        );

}


/* =====================================================
   REQUIRED
===================================================== */

.required {

    color:
        #ffdddd;

    font-weight:
        bold;

}


/* =====================================================
   GENDER
===================================================== */

.gender-box {

    background:
        rgba(
            255,
            255,
            255,
            .10
        );

    padding:
        15px
        18px;

    border-radius:
        15px;

}


.gender-box label {

    margin-right:
        25px;

    cursor:
        pointer;

}


.gender-box input {

    margin-right:
        6px;

}


/* =====================================================
   SUBJECT SEARCH
===================================================== */

.subject-search-wrapper {

    position:
        relative;

}


.subject-search-wrapper i {

    position:
        absolute;

    left:
        15px;

    top:
        50%;

    transform:
        translateY(-50%);

    color:
        #64748b;

    z-index:
        2;

}


.subject-search {

    padding-left:
        43px;

}


/* =====================================================
   SUBJECT CONTAINER
===================================================== */

.subject-container {

    background:
        rgba(
            255,
            255,
            255,
            .12
        );

    border:
        1px solid
        rgba(
            255,
            255,
            255,
            .25
        );

    border-radius:
        18px;

    padding:
        15px;

    margin-top:
        10px;

}


/* =====================================================
   SUBJECT ACTION BUTTONS
===================================================== */

.subject-actions {

    display:
        flex;

    gap:
        10px;

    margin-bottom:
        12px;

    flex-wrap:
        wrap;

}


.subject-actions button {

    border:
        none;

    border-radius:
        10px;

    padding:
        7px
        13px;

    font-size:
        13px;

    font-weight:
        600;

}


/* =====================================================
   SUBJECT LIST
===================================================== */

.subject-list {

    max-height:
        300px;

    overflow-y:
        auto;

    background:
        rgba(
            255,
            255,
            255,
            .95
        );

    border-radius:
        12px;

    padding:
        8px;

}


.subject-item {

    display:
        flex;

    align-items:
        center;

    gap:
        10px;

    padding:
        10px
        12px;

    border-radius:
        9px;

    color:
        #1e293b;

    cursor:
        pointer;

    transition:
        .15s ease;

}


.subject-item:hover {

    background:
        #e0f2fe;

}


.subject-item input {

    width:
        18px;

    height:
        18px;

    cursor:
        pointer;

}


.subject-item.hidden {

    display:
        none;

}


/* =====================================================
   NO SUBJECT FOUND
===================================================== */

.no-subject {

    text-align:
        center;

    padding:
        20px;

    color:
        #64748b;

    display:
        none;

}


/* =====================================================
   SELECTED SUBJECTS
===================================================== */

.selected-subject-box {

    margin-top:
        12px;

    padding:
        12px
        15px;

    border-radius:
        12px;

    background:
        rgba(
            15,
            23,
            42,
            .35
        );

}


.selected-subject-title {

    font-size:
        13px;

    font-weight:
        700;

    margin-bottom:
        8px;

}


.selected-subjects {

    display:
        flex;

    flex-wrap:
        wrap;

    gap:
        6px;

}


.subject-badge {

    display:
        inline-flex;

    align-items:
        center;

    gap:
        5px;

    background:
        #dbeafe;

    color:
        #1e3a8a;

    border-radius:
        20px;

    padding:
        6px
        10px;

    font-size:
        12px;

    font-weight:
        600;

}


/* =====================================================
   SUBJECT COUNT
===================================================== */

.subject-count {

    font-size:
        13px;

    margin-top:
        10px;

    color:
        #e0f2fe;

}


/* =====================================================
   SAVE BUTTON
===================================================== */

.btn-save {

    width:
        100%;

    height:
        52px;

    border:
        0;

    border-radius:
        15px;

    background:
        white;

    color:
        #0056b3;

    font-weight:
        800;

    font-size:
        16px;

    transition:
        .2s ease;

}


.btn-save:hover {

    background:
        #e0f2fe;

    transform:
        translateY(-1px);

}


/* =====================================================
   ALERT
===================================================== */

.alert {

    border-radius:
        15px;

}


/* =====================================================
   MOBILE
===================================================== */

@media (
    max-width: 768px
) {

    .sidebar {

        width:
            210px;

    }


    .topbar {

        margin-left:
            210px;

        padding:
            0 20px;

    }


    .content {

        margin-left:
            210px;

        padding:
            20px;

    }

}


@media (
    max-width: 576px
) {

    .sidebar {

        position:
            relative;

        width:
            100%;

        height:
            auto;

    }


    .topbar {

        margin-left:
            0;

    }


    .content {

        margin-left:
            0;

    }

}


.schedule-registration-card{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.20);border-radius:18px;padding:20px;}
.schedule-table{margin-bottom:10px;}
.schedule-table th{background:#0b3d91;color:#fff;}
.schedule-table td{background:rgba(255,255,255,.95);}
.schedule-time:disabled, .schedule-time-input:disabled{background:#e9ecef;cursor:not-allowed;}

</style>

</head>


<body>


<!-- =====================================================
     SIDEBAR
===================================================== -->

<div class="sidebar">


    <img
        src="../assets/neust_logo.png"
        class="logo"
        alt="NEUST Logo"
    >


    <h4>
        NEUST Lab System
    </h4>


    <a
        href="../dashboard.php"
    >

        <i
            class="bi bi-grid-fill"
        ></i>

        <span>
            Dashboard
        </span>

    </a>


    <a
        href="add.php"
        class="active"
    >

        <i
            class="bi bi-person-plus-fill"
        ></i>

        <span>
            Add Student
        </span>

    </a>


    <a
        href="list.php"
    >

        <i
            class="bi bi-people-fill"
        ></i>

        <span>
            Student List
        </span>

    </a>


    <a
        href="../attendance/system_logs.php"
    >

        <i
            class="bi bi-clock-history"
        ></i>

        <span>
            System Logs
        </span>

    </a>


    <a
        href="../attendance/records.php"
    >

        <i
            class="bi bi-calendar-check"
        ></i>

        <span>
            Records
        </span>

    </a>


    <a
        href="../logout.php"
    >

        <i
            class="bi bi-box-arrow-right"
        ></i>

        <span>
            Logout
        </span>

    </a>


</div>


<!-- =====================================================
     TOPBAR
===================================================== -->

<div class="topbar">


    <strong>

        <i
            class="bi bi-person-plus-fill"
        ></i>

        Add Student

    </strong>


    <div>

        <i
            class="bi bi-person-circle"
        ></i>

        <?= htmlspecialchars(
            $_SESSION['user']['first_name']
            ?? 'User'
        ); ?>

    </div>


</div>


<!-- =====================================================
     CONTENT
===================================================== -->

<div class="content">


    <div class="card-box">


        <h3 class="mb-4">

            <i
                class="bi bi-person-plus-fill"
            ></i>

            Add Student

        </h3>


        <!-- =================================================
             MESSAGE
        ================================================= -->

        <?php if ($message !== ''): ?>

            <div
                class="alert alert-<?= htmlspecialchars($messageType); ?>"
            >

                <?= htmlspecialchars($message); ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             FORM
        ================================================= -->

        <form
            method="POST"
            action=""
            id="studentForm"
        >


            <!-- =============================================
                 STUDENT NUMBER + RFID
            ============================================== -->

            <div class="row">


                <!-- STUDENT NUMBER -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">

                        Student Number

                        <span class="required">
                            
                        </span>

                    </label>


                    <input
                        type="text"
                        class="form-control"
                        name="student_No"
                        placeholder="Enter Student Number"
                        value="<?= htmlspecialchars(
                            $_POST['student_No'] ?? ''
                        ); ?>"
                        required
                    >

                </div>


                <!-- RFID -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">

                        RFID Code

                        <span class="required">
                            
                        </span>

                    </label>


                    <input
                        type="text"
                        class="form-control"
                        name="rfid"
                        placeholder="Enter RFID UID"
                        value="<?= htmlspecialchars(
                            $_POST['rfid'] ?? ''
                        ); ?>"
                        required
                    >

                </div>


            </div>


            <!-- =============================================
                 YEAR LEVEL + SECTION
            ============================================== -->

            <div class="row">


                <!-- YEAR LEVEL -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">

                        Year Level

                        <span class="required">
                            
                        </span>

                    </label>


                    <select
                        class="form-select"
                        name="year_level"
                        required
                    >

                        <option value="">
                            Select Year Level
                        </option>


                        <?php

                        $yearLevels = [
                            '1st Year',
                            '2nd Year',
                            '3rd Year',
                            '4th Year'
                        ];

                        foreach ($yearLevels as $yearOption):

                            $selected =
                                (
                                    ($_POST['year_level'] ?? '')
                                    === $yearOption
                                )
                                ? 'selected'
                                : '';

                        ?>

                            <option
                                value="<?= htmlspecialchars($yearOption); ?>"
                                <?= $selected; ?>
                            >

                                <?= htmlspecialchars($yearOption); ?>

                            </option>

                        <?php

                        endforeach;

                        ?>

                    </select>

                </div>


                <!-- SECTION -->

                <div class="col-md-6 mb-3">

                    <label class="form-label">

                        Section

                        <span class="required">
                            
                        </span>

                    </label>


                    <input
                        type="text"
                        class="form-control"
                        name="section"
                        placeholder="Example: BSIT-4A"
                        value="<?= htmlspecialchars(
                            $_POST['section'] ?? ''
                        ); ?>"
                        required
                    >

                </div>


            </div>


            <!-- =============================================
                 MULTIPLE SUBJECTS
            ============================================== -->

            <div class="mb-4">


                <label class="form-label">

                    Subjects

                    <span class="required">
                        
                    </span>

                </label>


                <!-- =========================================
                     SEARCH
                ========================================== -->

                <div
                    class="subject-search-wrapper"
                >

                    <i
                        class="bi bi-search"
                    ></i>


                    <input
                        type="text"
                        id="subjectSearch"
                        class="form-control subject-search"
                        placeholder="Search subject..."
                        autocomplete="off"
                    >

                </div>


                <!-- =========================================
                     SUBJECT CONTAINER
                ========================================== -->

                <div
                    class="subject-container"
                >


                    <!-- =====================================
                         ACTION BUTTONS
                    ====================================== -->

                    <div
                        class="subject-actions"
                    >

                        <button
                            type="button"
                            class="btn btn-light btn-sm"
                            id="selectAllSubjects"
                        >

                            <i
                                class="bi bi-check2-all"
                            ></i>

                            Select All

                        </button>


                        <button
                            type="button"
                            class="btn btn-light btn-sm"
                            id="clearSubjects"
                        >

                            <i
                                class="bi bi-x-circle"
                            ></i>

                            Clear All

                        </button>


                    </div>


                    <!-- =====================================
                         SUBJECT LIST
                    ====================================== -->

                    <div
                        class="subject-list"
                        id="subjectList"
                    >


                        <?php foreach (
                            $bsitSubjects
                            as $index => $subject
                        ): ?>


                            <label
                                class="subject-item"
                                data-subject="<?= htmlspecialchars(
                                    strtolower($subject)
                                ); ?>"
                            >


                                <input
                                    type="checkbox"
                                    name="subjects[]"
                                    value="<?= htmlspecialchars(
                                        $subject
                                    ); ?>"
                                    <?= (
                                        in_array(
                                            $subject,
                                            $selectedSubjects ?? [],
                                            true
                                        )
                                    )
                                    ? 'checked'
                                    : ''; ?>
                                >


                                <span>

                                    <?= htmlspecialchars(
                                        $subject
                                    ); ?>

                                </span>


                            </label>


                        <?php endforeach; ?>


                        <div
                            class="no-subject"
                            id="noSubject"
                        >

                            <i
                                class="bi bi-search"
                            ></i>

                            <br>

                            No subject found.

                        </div>


                    </div>


                    <!-- =====================================
                         SELECTED SUBJECTS
                    ====================================== -->

                    <div
                        class="selected-subject-box"
                    >


                        <div
                            class="selected-subject-title"
                        >

                            Selected Subjects:

                        </div>


                        <div
                            class="selected-subjects"
                            id="selectedSubjects"
                        >

                        </div>


                        <div
                            class="subject-count"
                            id="subjectCount"
                        >

                            0 subject(s) selected

                        </div>


                    </div>


                </div>


            </div>


            <!-- =============================================
                 CLASS SCHEDULE REGISTRATION
            ============================================== -->
            <div class="mb-4 schedule-registration-card">
                <label class="form-label fw-bold"><i class="bi bi-calendar-week"></i> Class Schedule</label>
                <p class="small text-muted mb-3">Select class days and assign Time In and Time Out using either the select dropdown or direct time picker input.</p>
                <?php
                $scheduleDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                $timeOptions = [];
                for ($minutes=0; $minutes<1440; $minutes+=15) {
                    $h=intdiv($minutes,60); $m=$minutes%60;
                    $value=sprintf('%02d:%02d:00',$h,$m);
                    $timeOptions[]=[$value,date('h:i A',strtotime($value))];
                }
                ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle schedule-table">
                        <thead>
                            <tr>
                                <th style="width:70px">Use</th>
                                <th>Day</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($scheduleDays as $day):
                            $oldDay=$_POST['schedule'][$day]??[];
                            $oldIn=is_array($oldDay)?($oldDay['time_in']??''):'';
                            $oldInInput=is_array($oldDay)?($oldDay['time_in_input']??''):'';
                            $oldOut=is_array($oldDay)?($oldDay['time_out']??''):'';
                            $oldOutInput=is_array($oldDay)?($oldDay['time_out_input']??''):'';
                            
                            $checked=($oldIn!==''||$oldInInput!==''||$oldOut!==''||$oldOutInput !== '');
                        ?>
                        <tr>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input schedule-day-toggle" data-day="<?= htmlspecialchars($day); ?>" <?= $checked?'checked':''; ?>>
                            </td>
                            <td class="fw-semibold"><?= htmlspecialchars($day); ?></td>
                            <td>
                                <div class="d-flex flex-column gap-2">
                                    <select class="form-select schedule-time" name="schedule[<?= htmlspecialchars($day); ?>][time_in]" data-type="time_in" data-day="<?= htmlspecialchars($day); ?>" <?= $checked?'':'disabled'; ?>>
                                        <option value="">Select Time In</option>
                                        <?php foreach($timeOptions as [$value,$label]): ?>
                                            <option value="<?= $value; ?>" <?= $oldIn===$value?'selected':''; ?>><?= $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="time" class="form-control schedule-time-input" name="schedule[<?= htmlspecialchars($day); ?>][time_in_input]" data-type="time_in_input" data-day="<?= htmlspecialchars($day); ?>" value="<?= htmlspecialchars($oldInInput); ?>" <?= $checked?'':'disabled'; ?>>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-2">
                                    <select class="form-select schedule-time" name="schedule[<?= htmlspecialchars($day); ?>][time_out]" data-type="time_out" data-day="<?= htmlspecialchars($day); ?>" <?= $checked?'':'disabled'; ?>>
                                        <option value="">Select Time Out</option>
                                        <?php foreach($timeOptions as [$value,$label]): ?>
                                            <option value="<?= $value; ?>" <?= $oldOut===$value?'selected':''; ?>><?= $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="time" class="form-control schedule-time-input" name="schedule[<?= htmlspecialchars($day); ?>][time_out_input]" data-type="time_out_input" data-day="<?= htmlspecialchars($day); ?>" value="<?= htmlspecialchars($oldOutInput); ?>" <?= $checked?'':'disabled'; ?>>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="small text-muted"><i class="bi bi-info-circle"></i> Philippine time (Asia/Manila), displayed in 12-hour AM/PM format.</div>
            </div>

            <!-- =============================================
                 FIRST NAME
            ============================================== -->

            <div class="mb-3">


                <label class="form-label">

                    First Name

                    <span class="required">
                        
                    </span>

                </label>


                <input
                    type="text"
                    class="form-control"
                    name="first_name"
                    placeholder="Enter First Name"
                    value="<?= htmlspecialchars(
                        $_POST['first_name'] ?? ''
                    ); ?>"
                    required
                >


            </div>


            <!-- =============================================
                 MIDDLE NAME
            ============================================== -->

            <div class="mb-3">


                <label class="form-label">

                    Middle Name

                </label>


                <input
                    type="text"
                    class="form-control"
                    name="middle_name"
                    placeholder="Enter Middle Name"
                    value="<?= htmlspecialchars(
                        $_POST['middle_name'] ?? ''
                    ); ?>"
                >


            </div>


            <!-- =============================================
                 LAST NAME
            ============================================== -->

            <div class="mb-3">


                <label class="form-label">

                    Last Name

                    <span class="required">
                        
                    </span>

                </label>


                <input
                    type="text"
                    class="form-control"
                    name="last_name"
                    placeholder="Enter Last Name"
                    value="<?= htmlspecialchars(
                        $_POST['last_name'] ?? ''
                    ); ?>"
                    required
                >


            </div>


            <!-- =============================================
                 EXTENSION
            ============================================== -->

            <div class="mb-3">


                <label class="form-label">

                    Extension Name

                </label>


                <input
                    type="text"
                    class="form-control"
                    name="extension_name"
                    placeholder="Jr., Sr., III"
                    value="<?= htmlspecialchars(
                        $_POST['extension_name'] ?? ''
                    ); ?>"
                >


            </div>


            <!-- =============================================
                 GENDER
            ============================================== -->

            <div class="mb-4">


                <label class="form-label">

                    Gender

                    <span class="required">
                        
                    </span>

                </label>


                <div
                    class="gender-box"
                >


                    <label>

                        <input
                            type="radio"
                            name="gender"
                            value="Male"
                            <?= (
                                ($_POST['gender'] ?? '')
                                === 'Male'
                            )
                            ? 'checked'
                            : ''; ?>
                            required
                        >

                        Male

                    </label>


                    <label>

                        <input
                            type="radio"
                            name="gender"
                            value="Female"
                            <?= (
                                ($_POST['gender'] ?? '')
                                === 'Female'
                            )
                            ? 'checked'
                            : ''; ?>
                            required
                        >

                        Female

                    </label>


                </div>


            </div>


            <!-- =============================================
                 SAVE BUTTON
            ============================================== -->

            <button
                type="submit"
                class="btn-save"
                name="save"
            >

                <i
                    class="bi bi-save"
                ></i>

                Save Student

            </button>


        </form>


    </div>


</div>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        // =============================================
        // ELEMENTS
        // =============================================

        const searchInput =
            document.getElementById(
                'subjectSearch'
            );


        const subjectItems =
            document.querySelectorAll(
                '.subject-item'
            );


        const checkboxes =
            document.querySelectorAll(
                '.subject-item input[type="checkbox"]'
            );


        const selectedContainer =
            document.getElementById(
                'selectedSubjects'
            );


        const subjectCount =
            document.getElementById(
                'subjectCount'
            );


        const noSubject =
            document.getElementById(
                'noSubject'
            );


        const selectAllButton =
            document.getElementById(
                'selectAllSubjects'
            );


        const clearButton =
            document.getElementById(
                'clearSubjects'
            );


        // =============================================
        // UPDATE SELECTED SUBJECT DISPLAY
        // =============================================

        function updateSelectedSubjects()
        {

            selectedContainer.innerHTML = '';


            let count = 0;


            checkboxes.forEach(
                function (checkbox)
                {

                    if (
                        checkbox.checked
                    ) {

                        count++;


                        const badge =
                            document.createElement(
                                'span'
                            );


                        badge.className =
                            'subject-badge';


                        const icon =
                            document.createElement(
                                'i'
                            );


                        icon.className =
                            'bi bi-book';


                        const text =
                            document.createTextNode(
                                checkbox.value
                            );


                        badge.appendChild(
                            icon
                        );


                        badge.appendChild(
                            text
                        );


                        selectedContainer.appendChild(
                            badge
                        );

                    }

                }
            );


            subjectCount.textContent =
                count +
                (
                    count === 1
                    ? ' subject selected'
                    : ' subjects selected'
                );

        }


        // =============================================
        // SEARCH SUBJECT
        // =============================================

        function searchSubjects()
        {

            const searchValue =
                searchInput.value
                    .toLowerCase()
                    .trim();


            let visibleCount = 0;


            subjectItems.forEach(
                function (item)
                {

                    const subject =
                        item.dataset.subject;


                    if (
                        subject.includes(
                            searchValue
                        )
                    ) {

                        item.classList.remove(
                            'hidden'
                        );


                        visibleCount++;

                    }

                    else {

                        item.classList.add(
                            'hidden'
                        );

                    }

                }
            );


            // Show "No subject found"
            if (
                visibleCount === 0
            ) {

                noSubject.style.display =
                    'block';

            }

            else {

                noSubject.style.display =
                    'none';

            }

        }


        // =============================================
        // SEARCH EVENT
        // =============================================

        searchInput.addEventListener(
            'input',
            searchSubjects
        );


        // =============================================
        // CHECKBOX EVENTS
        // =============================================

        checkboxes.forEach(
            function (checkbox)
            {

                checkbox.addEventListener(
                    'change',
                    updateSelectedSubjects
                );

            }
        );


        // =============================================
        // SELECT ALL
        // =============================================

        selectAllButton.addEventListener(
            'click',
            function ()
            {

                checkboxes.forEach(
                    function (checkbox)
                    {

                        // Only select visible subjects
                        const item =
                            checkbox.closest(
                                '.subject-item'
                            );


                        if (
                            !item.classList.contains(
                                'hidden'
                            )
                        ) {

                            checkbox.checked =
                                true;

                        }

                    }
                );


                updateSelectedSubjects();

            }
        );


        // =============================================
        // CLEAR ALL
        // =============================================

        clearButton.addEventListener(
            'click',
            function ()
            {

                checkboxes.forEach(
                    function (checkbox)
                    {

                        checkbox.checked =
                            false;

                    }
                );


                updateSelectedSubjects();

            }
        );


        // =============================================
        // INITIAL UPDATE
        // =============================================

        updateSelectedSubjects();


        // =============================================
        // INITIAL SEARCH
        // =============================================

        searchSubjects();


    }
);



// Class schedule day toggles
document.querySelectorAll('.schedule-day-toggle').forEach(function(toggle){
    toggle.addEventListener('change',function(){
        const day=this.dataset.day;
        
        document.querySelectorAll('.schedule-time[data-day="'+day+'"]').forEach(function(select){
            select.disabled=!toggle.checked;
            if(!toggle.checked){select.value='';}
        });

        document.querySelectorAll('.schedule-time-input[data-day="'+day+'"]').forEach(function(input){
            input.disabled=!toggle.checked;
            if(!toggle.checked){input.value='';}
        });
    });
});

// Sync schedule selects and time inputs
document.querySelectorAll('.schedule-time').forEach(function(select){
    select.addEventListener('change', function(){
        const day = this.dataset.day;
        const targetType = this.dataset.type === 'time_in' ? 'time_in_input' : 'time_out_input';
        const targetInput = document.querySelector('.schedule-time-input[data-day="'+day+'"][data-type="'+targetType+'"]');
        if (targetInput) {
            targetInput.value = this.value ? this.value.substring(0, 5) : '';
        }
    });
});

document.querySelectorAll('.schedule-time-input').forEach(function(input){
    input.addEventListener('input', function(){
        const day = this.dataset.day;
        const targetType = this.dataset.type === 'time_in_input' ? 'time_in' : 'time_out';
        const targetSelect = document.querySelector('.schedule-time[data-day="'+day+'"][data-type="'+targetType+'"]');
        if (targetSelect) {
            const val = this.value ? (this.value.length === 5 ? this.value + ':00' : this.value) : '';
            targetSelect.value = val;
        }
    });
});
</script>


</body>

</html>