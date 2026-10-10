<?php

include '../config/db.php';
session_start();

date_default_timezone_set('Asia/Manila');


// =====================================================
// LOGIN CHECK
// =====================================================

if (!isset($_SESSION['user'])) {

    header("Location: ../index.php");

    exit();

}


// =====================================================
// GET STUDENTS
// =====================================================

$students = [];
$dbError = '';

/*
 * Do not filter students by year, section, status, RFID, subject,
 * or registration date here. The list page must receive EVERY
 * existing student from the students table.
 */
$result = $conn->query("
    SELECT *
    FROM students
    ORDER BY stud_id DESC
");

if ($result === false) {
    $dbError = $conn->error;
} else {
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }

    $result->free();
}


// =====================================================
// GET STUDENT SUBJECTS
// =====================================================

function getStudentSubjects($subjectData)
{

    if (
        $subjectData === null ||
        trim($subjectData) === ''
    ) {

        return [];

    }


    // New format: JSON array
    $subjects = json_decode(
        $subjectData,
        true
    );


    if (
        json_last_error() === JSON_ERROR_NONE &&
        is_array($subjects)
    ) {

        return array_values(

            array_filter(

                array_map(
                    'trim',
                    $subjects
                ),

                function ($subject) {

                    return $subject !== '';

                }

            )

        );

    }


    // Old format: single plain-text subject
    return [
        trim($subjectData)
    ];

}


// =====================================================
// GET CLASS SCHEDULE
// =====================================================

function getStudentSchedule($scheduleData)
{
    if ($scheduleData === null || trim((string)$scheduleData) === '') {
        return [];
    }

    $schedule = json_decode($scheduleData, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($schedule)) {
        return array_values(array_filter($schedule, function ($item) {
            return is_array($item) && !empty($item['day']);
        }));
    }

    return [];
}

function formatScheduleTime($time)
{
    if (!$time) return '-';
    $timestamp = strtotime($time);
    return $timestamp ? date('h:i A', $timestamp) : $time;
}


// =====================================================
// GET YEAR LEVEL
//
// New database field:
// year_level
//
// Expected values:
// 1st Year
// 2nd Year
// 3rd Year
// 4th Year
//
// Fallback:
// If old data does not have year_level, determine it
// from section such as BSIT-4A.
// =====================================================

function getStudentYearLevel($student)
{
    $rawYear = trim((string)($student['year_level'] ?? ''));

    if ($rawYear !== '') {
        $normalized = strtolower(preg_replace('/\s+/', ' ', $rawYear));

        if (in_array($normalized, ['1', '1st', '1st year', 'first year', 'first'], true)) {
            return '1st Year';
        }

        if (in_array($normalized, ['2', '2nd', '2nd year', 'second year', 'second'], true)) {
            return '2nd Year';
        }

        if (in_array($normalized, ['3', '3rd', '3rd year', 'third year', 'third'], true)) {
            return '3rd Year';
        }

        if (in_array($normalized, ['4', '4th', '4th year', 'fourth year', 'fourth'], true)) {
            return '4th Year';
        }

        // Handle values such as "Year 1", "YEAR-2", etc.
        if (preg_match('/\b1(?:st)?\b/', $normalized)) return '1st Year';
        if (preg_match('/\b2(?:nd)?\b/', $normalized)) return '2nd Year';
        if (preg_match('/\b3(?:rd)?\b/', $normalized)) return '3rd Year';
        if (preg_match('/\b4(?:th)?\b/', $normalized)) return '4th Year';
    }

    // Fallback for older records where year_level is empty.
    $section = strtoupper(trim((string)($student['section'] ?? '')));

    if (preg_match('/(?:BSIT[-\s]*)?([1-4])/', $section, $matches)) {
        switch ((int)$matches[1]) {
            case 1: return '1st Year';
            case 2: return '2nd Year';
            case 3: return '3rd Year';
            case 4: return '4th Year';
        }
    }

    return '';
}

// =====================================================
// YEAR LEVEL ORDER
// =====================================================

$yearLevels = [
    '1st Year',
    '2nd Year',
    '3rd Year',
    '4th Year'
];

// Start with 1st Year. Students are loaded from the database
// and can be viewed through the four year-level tabs.
$selectedYearFromRequest = $_GET['year'] ?? '1st Year';

if (!in_array($selectedYearFromRequest, $yearLevels, true)) {
    $selectedYearFromRequest = '1st Year';
}


// =====================================================
// ORGANIZE STUDENTS
//
// Structure:
// $organizedStudents['1st Year']['BSIT-1A']
// $organizedStudents['2nd Year']['BSIT-2A']
// $organizedStudents['3rd Year']['BSIT-3A']
// $organizedStudents['4th Year']['BSIT-4A']
// =====================================================

$organizedStudents = [];

$totalStudents = count(
    $students
);


foreach (
    $yearLevels as $yearLevel
) {

    $organizedStudents[
        $yearLevel
    ] = [];

}


// =====================================================
// ORGANIZE DATABASE RECORDS
// =====================================================

foreach (
    $students as $student
) {

    $yearLevel =
        getStudentYearLevel(
            $student
        );


    $section = trim(
        $student['section'] ?? ''
    );


    if (
        $section === ''
    ) {

        $section = 'No Section';

    }


    // Only the four supported year levels are displayed.
    // Records with an invalid/missing year level are skipped.
    if (!isset($organizedStudents[$yearLevel])) {
        continue;
    }


    if (
        !isset(
            $organizedStudents
            [$yearLevel]
            [$section]
        )
    ) {

        $organizedStudents
        [$yearLevel]
        [$section] = [];

    }


    $organizedStudents
    [$yearLevel]
    [$section][] = $student;

}


// =====================================================
// COUNT STUDENTS PER YEAR
// =====================================================

$yearCounts = [];

foreach (
    $organizedStudents as
    $yearLevel => $sections
) {

    $yearCounts[$yearLevel] = 0;


    foreach (
        $sections as $sectionStudents
    ) {

        $yearCounts[$yearLevel] +=
            count(
                $sectionStudents
            );

    }

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0"
>

<title>NEUST Student List</title>


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

    box-sizing: border-box;

}


html,
body {

    margin: 0;

    padding: 0;

    min-height: 100%;

}


body {

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

    width: 260px;

    height: 100vh;

    position: fixed;

    top: 0;

    left: 0;

    background:

        rgba(15,23,42,.92);

    backdrop-filter: blur(15px);

    color: white;

    padding: 25px 15px;

    z-index: 1000;

}


.logo {

    width: 85px;

    height: 85px;

    background: white;

    padding: 8px;

    border-radius: 50%;

    object-fit: contain;

    display: block;

    margin: auto;

}


.sidebar h4 {

    text-align: center;

    font-weight: 800;

    margin: 15px 0 30px;

}


.sidebar a {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 13px 18px;

    margin: 8px 0;

    border-radius: 14px;

    color: #dbeafe;

    text-decoration: none;

    transition: all .2s ease;

}


.sidebar a:hover,
.sidebar a.active {

    background:

        linear-gradient(
            90deg,
            #2563eb,
            #38bdf8
        );

    color: white;

    transform: translateX(3px);

}


/* =====================================================
   TOPBAR
===================================================== */

.topbar {

    margin-left: 260px;

    height: 75px;

    padding: 0 35px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    background:

        rgba(255,255,255,.15);

    backdrop-filter: blur(15px);

    color: white;

    position: relative;

    z-index: 50;

}


/* =====================================================
   CONTENT
===================================================== */

.content {

    margin-left: 260px;

    padding: 40px;

}


/* =====================================================
   MAIN CARD
===================================================== */

.table-card {

    background:

        rgba(255,255,255,.18);

    backdrop-filter: blur(18px);

    border-radius: 25px;

    padding: 30px;

    box-shadow:

        0 25px 60px rgba(0,0,0,.25);

    color: white;

}


/* =====================================================
   PAGE TITLE
===================================================== */

.page-title {

    font-size: 29px;

    font-weight: 800;

    margin: 0;

}


.page-subtitle {

    margin-top: 5px;

    color: #dbeafe;

}


/* =====================================================
   REGISTER BUTTON
===================================================== */

.btn-register {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    background: white;

    color: #0056b3;

    font-weight: 800;

    border-radius: 13px;

    padding: 10px 17px;

    text-decoration: none;

    transition: .2s;

}


.btn-register:hover {

    background: #e0f2fe;

    color: #003b75;

    transform: translateY(-2px);

}


/* =====================================================
   YEAR NAVIGATION
===================================================== */

.year-navigation {

    margin-top: 30px;

}


.year-navigation-label {

    font-size: 12px;

    text-transform: uppercase;

    letter-spacing: 1px;

    font-weight: 800;

    color: #dbeafe;

    margin-bottom: 12px;

}


.year-buttons {

    display: flex;

    flex-wrap: wrap;

    gap: 10px;

}


.year-button {

    display: inline-flex;

    align-items: center;

    gap: 8px;

    border: 1px solid
        rgba(255,255,255,.20);

    background:
        rgba(255,255,255,.10);

    color: white;

    padding: 11px 20px;

    border-radius: 13px;

    font-weight: 800;

    cursor: pointer;

    transition: .2s;

}


.year-button:hover {

    background:
        rgba(255,255,255,.20);

    transform: translateY(-2px);

}


.year-button.active {

    background:

        linear-gradient(
            135deg,
            #2563eb,
            #0ea5e9
        );

    border-color: transparent;

    box-shadow:

        0 8px 22px
        rgba(14,165,233,.25);

}

.year-button .badge {
    font-size: 10px;
    border-radius: 999px;
    padding: 4px 7px;
    margin-left: 2px;
}

.year-button.active .badge {
    background: rgba(255,255,255,.92) !important;
    color: #0056b3 !important;
}


/* =====================================================
   YEAR CONTENT
===================================================== */

.year-content {

    margin-top: 25px;

}


/* =====================================================
   YEAR HEADER
===================================================== */

.year-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    padding: 20px;

    margin-bottom: 25px;

    border-radius: 18px;

    background:

        linear-gradient(
            135deg,
            rgba(0,31,63,.75),
            rgba(0,86,179,.55)
        );

    border:

        1px solid
        rgba(255,255,255,.12);

}


.year-header-left {

    display: flex;

    align-items: center;

    gap: 14px;

}


.year-icon {

    width: 52px;

    height: 52px;

    min-width: 52px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 14px;

    background:
        rgba(255,255,255,.13);

    font-size: 23px;

}


.year-header h4 {

    margin: 0;

    font-weight: 800;

}


.year-header small {

    color: #dbeafe;

}


.year-count {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 8px 13px;

    border-radius: 10px;

    background:
        rgba(255,255,255,.12);

    font-size: 13px;

    font-weight: 700;

}


/* =====================================================
   YEAR LEVEL CARD
===================================================== */

.year-level-container {

    margin-bottom: 30px;

}


.year-level-heading {

    display: flex;

    align-items: center;

    gap: 12px;

    margin-bottom: 14px;

}


.year-level-icon {

    width: 44px;

    height: 44px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 12px;

    background:
        rgba(255,255,255,.13);

    border:
        1px solid
        rgba(255,255,255,.12);

    font-size: 18px;

}


.year-level-heading h5 {

    margin: 0;

    font-size: 18px;

    font-weight: 800;

}


.year-level-heading small {

    color: #dbeafe;

}


/* =====================================================
   SECTION GRID
===================================================== */

.section-grid {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fill,
            minmax(220px, 1fr)
        );

    gap: 14px;

}


/* =====================================================
   SECTION CARD
===================================================== */

.section-card {

    padding: 19px;

    border-radius: 17px;

    background:
        rgba(255,255,255,.11);

    border:
        1px solid
        rgba(255,255,255,.15);

    cursor: pointer;

    transition:

        transform .2s ease,
        background .2s ease,
        box-shadow .2s ease;

}


.section-card:hover {

    transform: translateY(-4px);

    background:
        rgba(255,255,255,.20);

    box-shadow:
        0 12px 30px
        rgba(0,0,0,.18);

}


.section-card.active {

    background:

        linear-gradient(
            135deg,
            rgba(37,99,235,.85),
            rgba(14,165,233,.75)
        );

}


.section-top {

    display: flex;

    align-items: center;

    justify-content: space-between;

}


.section-icon {

    width: 43px;

    height: 43px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 12px;

    background:
        rgba(255,255,255,.15);

}


.section-count {

    padding: 5px 9px;

    border-radius: 8px;

    background:
        rgba(255,255,255,.14);

    font-size: 11px;

    font-weight: 800;

}


.section-name {

    margin-top: 14px;

    font-size: 18px;

    font-weight: 800;

}


.section-description {

    margin-top: 4px;

    color: #dbeafe;

    font-size: 12px;

}


/* =====================================================
   STUDENT PANEL
===================================================== */

.student-panel {

    display: none;

    margin-top: 25px;

    padding: 20px;

    border-radius: 20px;

    background:
        rgba(255,255,255,.10);

    border:
        1px solid
        rgba(255,255,255,.12);

}


.student-panel.show {

    display: block;

}


.student-panel-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-bottom: 18px;

}


.student-panel-title {

    display: flex;

    align-items: center;

    gap: 11px;

}


.student-panel-title h5 {

    margin: 0;

    font-weight: 800;

}


.student-panel-title small {

    color: #dbeafe;

}


.close-section {

    width: 38px;

    height: 38px;

    border: none;

    border-radius: 10px;

    background:
        rgba(255,255,255,.10);

    color: white;

    cursor: pointer;

}


.close-section:hover {

    background:
        rgba(255,255,255,.20);

}


/* =====================================================
   TABLE
===================================================== */

.table-responsive {

    border-radius: 14px;

    overflow-x: auto;

}


.table {

    color: white;

    margin-bottom: 0;

}


.table thead {

    background: #0056b3;

    color: white;

}


.table thead th {

    white-space: nowrap;

    border-color:
        rgba(255,255,255,.15);

}


.table tbody td {

    border-color:
        rgba(255,255,255,.12);

}


.table tbody tr:hover {

    background:
        rgba(255,255,255,.10);

}


/* =====================================================
   CLASS SCHEDULE BUTTON / MODAL
===================================================== */

.btn-view-schedule {
    display:inline-flex;
    align-items:center;
    gap:6px;
    border:none;
    border-radius:9px;
    padding:7px 11px;
    background:linear-gradient(135deg,#059669,#10b981);
    color:white;
    font-size:12px;
    font-weight:700;
    cursor:pointer;
    transition:.2s;
    margin-left:5px;
}

.btn-view-schedule:hover { color:white; transform:translateY(-2px); }

.schedule-modal-list {
    max-height:380px;
    overflow-y:auto;
}

.schedule-view-item {
    display:grid;
    grid-template-columns:150px 1fr;
    gap:15px;
    align-items:center;
    background:white;
    border:1px solid #e2e8f0;
    border-radius:13px;
    padding:14px 16px;
    margin-bottom:9px;
    color:#1e293b;
}

.schedule-view-day { font-weight:800; }
.schedule-view-time { font-weight:700; color:#0369a1; }
.schedule-view-note { color:#64748b; font-size:12px; margin-top:10px; }

@media(max-width:768px){
    .schedule-view-item { grid-template-columns:1fr; gap:5px; }
}


/* =====================================================
   SUBJECT VIEW BUTTON
===================================================== */

.btn-view-subject {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    border: none;

    border-radius: 9px;

    padding: 7px 11px;

    background:

        linear-gradient(
            135deg,
            #0284c7,
            #2563eb
        );

    color: white;

    font-size: 12px;

    font-weight: 700;

    cursor: pointer;

    transition: .2s;

}


.btn-view-subject:hover {

    color: white;

    transform: translateY(-2px);

    box-shadow:
        0 7px 18px
        rgba(0,0,0,.25);

}


.subject-count {

    display: inline-block;

    margin-left: 4px;

    padding: 5px 8px;

    border-radius: 7px;

    background:
        rgba(255,255,255,.15);

    font-size: 11px;

}


/* =====================================================
   SUBJECT MODAL OVERLAY
===================================================== */

.subject-modal-overlay {

    position: fixed;

    inset: 0;

    width: 100%;

    height: 100%;

    display: none;

    align-items: center;

    justify-content: center;

    padding: 20px;

    background:
        rgba(2,15,35,.78);

    backdrop-filter: blur(5px);

    -webkit-backdrop-filter: blur(5px);

    z-index: 99999;

}


.subject-modal-overlay.show {

    display: flex;

}


body.subject-modal-open {

    overflow: hidden;

}


/* =====================================================
   SUBJECT MODAL
===================================================== */

.subject-modal {

    width: 100%;

    max-width: 950px;

    max-height: 90vh;

    background: #f8fafc;

    border-radius: 24px;

    overflow: hidden;

    box-shadow:
        0 30px 100px
        rgba(0,0,0,.55);

    display: flex;

    flex-direction: column;

}


/* =====================================================
   MODAL HEADER
===================================================== */

.subject-modal-header {

    position: relative;

    padding: 26px 30px;

    color: white;

    background:

        linear-gradient(
            135deg,
            #003b73,
            #0056b3,
            #0ea5e9
        );

}


.modal-title {

    display: flex;

    align-items: center;

    gap: 15px;

    padding-right: 45px;

}


.modal-icon {

    width: 58px;

    height: 58px;

    min-width: 58px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 16px;

    background:
        rgba(255,255,255,.16);

    font-size: 25px;

}


.subject-modal-header h3 {

    margin: 0;

    font-size: 25px;

    font-weight: 800;

}


.subject-modal-header p {

    margin: 4px 0 0;

    color: #dbeafe;

    font-size: 13px;

}


.subject-total {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    margin-top: 12px;

    padding: 7px 12px;

    border-radius: 10px;

    background:
        rgba(255,255,255,.15);

    font-size: 13px;

    font-weight: 700;

}


.modal-close {

    position: absolute;

    top: 20px;

    right: 20px;

    width: 42px;

    height: 42px;

    border: none;

    border-radius: 12px;

    background:
        rgba(255,255,255,.13);

    color: white;

    font-size: 21px;

    cursor: pointer;

}


.modal-close:hover {

    background:
        rgba(255,255,255,.25);

}


/* =====================================================
   STUDENT INFORMATION
===================================================== */

.student-information {

    padding: 20px 25px;

    background: #f8fafc;

    border-bottom:
        1px solid #e2e8f0;

}


.student-info-grid {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 12px;

}


.student-info-box {

    background: white;

    border:
        1px solid #e2e8f0;

    border-radius: 13px;

    padding: 12px 14px;

}


.student-info-label {

    font-size: 10px;

    color: #64748b;

    font-weight: 800;

    text-transform: uppercase;

    letter-spacing: .5px;

}


.student-info-value {

    margin-top: 4px;

    font-size: 14px;

    color: #1e293b;

    font-weight: 700;

}


/* =====================================================
   SUBJECT CONTENT
===================================================== */

.subject-content {

    padding: 20px 25px;

    background: #f8fafc;

    flex: 1;

    min-height: 0;

}


.subject-toolbar {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    margin-bottom: 15px;

}


.subject-toolbar-title {

    color: #1e293b;

    font-size: 16px;

    font-weight: 800;

}


.subject-search {

    position: relative;

    width: 320px;

}


.subject-search i {

    position: absolute;

    left: 13px;

    top: 50%;

    transform: translateY(-50%);

    color: #64748b;

}


.subject-search input {

    width: 100%;

    height: 42px;

    border:
        1px solid #cbd5e1;

    border-radius: 11px;

    padding:
        8px 15px 8px 38px;

    outline: none;

    color: #1e293b;

}


.subject-list {

    max-height: 380px;

    overflow-y: auto;

    padding-right: 4px;

}


.subject-list::-webkit-scrollbar {

    width: 7px;

}


.subject-list::-webkit-scrollbar-track {

    background: #e2e8f0;

}


.subject-list::-webkit-scrollbar-thumb {

    background: #94a3b8;

    border-radius: 10px;

}


.subject-item {

    display: flex;

    align-items: center;

    gap: 12px;

    background: white;

    border:
        1px solid #e2e8f0;

    border-radius: 13px;

    padding: 13px 15px;

    margin-bottom: 9px;

    transition: .15s;

}


.subject-item:hover {

    background: #f0f9ff;

    border-color: #7dd3fc;

    transform: translateX(3px);

}


.subject-number {

    width: 38px;

    height: 38px;

    min-width: 38px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 11px;

    background:
        linear-gradient(
            135deg,
            #dbeafe,
            #e0f2fe
        );

    color: #0369a1;

    font-weight: 800;

}


.subject-book {

    width: 36px;

    height: 36px;

    min-width: 36px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 10px;

    background: #eff6ff;

    color: #2563eb;

}


.subject-name {

    color: #1e293b;

    font-size: 14px;

    font-weight: 700;

}


/* =====================================================
   MODAL FOOTER
===================================================== */

.subject-footer {

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 15px 25px;

    background: white;

    border-top:
        1px solid #e2e8f0;

}


.subject-visible-count {

    color: #64748b;

    font-size: 13px;

}


.btn-done {

    border: none;

    background:

        linear-gradient(
            135deg,
            #0056b3,
            #0284c7
        );

    color: white;

    padding: 9px 20px;

    border-radius: 10px;

    font-weight: 700;

    cursor: pointer;

}


.btn-done:hover {

    color: white;

}


/* =====================================================
   NO DATA
===================================================== */

.no-data {

    text-align: center;

    padding: 70px 20px;

    color: #dbeafe;

}


.no-data i {

    font-size: 60px;

    display: block;

    margin-bottom: 15px;

}


/* =====================================================
   RESPONSIVE
===================================================== */

@media (max-width: 992px) {

    .sidebar {

        width: 220px;

    }


    .topbar,
    .content {

        margin-left: 220px;

    }


    .student-info-grid {

        grid-template-columns:
            repeat(2, 1fr);

    }

}


@media (max-width: 768px) {

    .sidebar {

        position: relative;

        width: 100%;

        height: auto;

    }


    .topbar,
    .content {

        margin-left: 0;

    }


    .content {

        padding: 20px;

    }


    .table-card {

        padding: 20px;

    }


    .year-header {

        align-items: flex-start;

        flex-direction: column;

    }


    .section-grid {

        grid-template-columns: 1fr;

    }


    .student-panel-header {

        align-items: flex-start;

    }


    .subject-toolbar {

        align-items: stretch;

        flex-direction: column;

    }


    .subject-search {

        width: 100%;

    }


    .student-info-grid {

        grid-template-columns: 1fr;

    }

}


@media (max-width: 500px) {

    .page-title {

        font-size: 23px;

    }


    .year-button {

        flex: 1;

        justify-content: center;

    }


    .subject-modal {

        max-height: 95vh;

        border-radius: 18px;

    }


    .subject-modal-header {

        padding: 22px;

    }


    .subject-content {

        padding: 15px;

    }


    .student-information {

        padding: 15px;

    }

}

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


<a href="../dashboard.php">

<i class="bi bi-grid-fill"></i>

Dashboard

</a>


<a href="add.php">

<i class="bi bi-person-plus-fill"></i>

Add Student

</a>


<a
href="list.php"
class="active"
>

<i class="bi bi-people-fill"></i>

Student List

</a>


<a href="../attendance/system_logs.php">

<i class="bi bi-clock-history"></i>

System Logs

</a>


<a href="../attendance/records.php">

<i class="bi bi-calendar-check"></i>

Records

</a>


<a href="../logout.php">

<i class="bi bi-box-arrow-right"></i>

Logout

</a>


</div>


<!-- =====================================================
     TOPBAR
===================================================== -->

<div class="topbar">


<strong>

<i class="bi bi-people-fill"></i>

&nbsp;

Student List

</strong>


<div>

<i class="bi bi-person-circle"></i>

<?= htmlspecialchars(
    $_SESSION['user']['first_name']
); ?>

</div>


</div>


<!-- =====================================================
     CONTENT
===================================================== -->

<div class="content">


<div class="table-card">

<?php if ($dbError !== ''): ?>
    <div class="alert alert-danger mb-4">
        <strong>Database error:</strong>
        <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<!-- =====================================================
     HEADER
===================================================== -->

<div
class="d-flex justify-content-between align-items-center"
>

<div>

<h3 class="page-title">

Student List

</h3>


<div class="page-subtitle">

Registered students from the database, organized by year and section

</div>

</div>


<a
href="add.php"
class="btn-register"
>

<i class="bi bi-person-plus-fill"></i>

Register

</a>


</div>


<!-- =====================================================
     YEAR NAVIGATION
===================================================== -->

<div class="year-navigation">


<div class="year-navigation-label">

<i class="bi bi-mortarboard-fill"></i>

&nbsp;

Year Level

</div>


<div class="year-buttons">


<?php foreach ($yearLevels as $yearLevel): ?>
    <button
        type="button"
        class="year-button <?= $yearLevel === $selectedYearFromRequest ? 'active' : ''; ?>"
        data-year="<?= htmlspecialchars($yearLevel, ENT_QUOTES, 'UTF-8'); ?>"
    >
        <i class="bi bi-mortarboard-fill"></i>
        <?= htmlspecialchars($yearLevel); ?>
        <span class="badge bg-light text-primary"><?= (int)($yearCounts[$yearLevel] ?? 0); ?></span>
    </button>
<?php endforeach; ?>


</div>

</div>


<!-- =====================================================
     YEAR CONTENT
===================================================== -->

<div
id="yearContent"
class="year-content"
>

</div>


<!-- =====================================================
     STUDENT PANEL
===================================================== -->

<div
id="studentPanel"
class="student-panel"
>


<div class="student-panel-header">


<div class="student-panel-title">


<i class="bi bi-people-fill"></i>


<div>

<h5 id="studentPanelTitle">

Students

</h5>


<small id="studentPanelSubtitle">

</small>

</div>


</div>


<button
type="button"
class="close-section"
id="closeStudentPanel"
title="Close"
>

<i class="bi bi-x-lg"></i>

</button>


</div>


<div class="table-responsive">


<table class="table table-hover align-middle">


<thead>

<tr>

<th>ID</th>

<th>Student No</th>

<th>Full Name</th>

<th>Year</th>

<th>Section</th>

<th>Subjects</th>

<th>RFID</th>

<th>Gender</th>

<th>Action</th>

</tr>

</thead>


<tbody id="studentTableBody">

</tbody>


</table>


</div>


</div>


</div>


</div>


<!-- =====================================================
     SUBJECT MODAL
===================================================== -->

<div
id="subjectModal"
class="subject-modal-overlay"
>


<div
class="subject-modal"
role="dialog"
aria-modal="true"
>


<!-- MODAL HEADER -->

<div class="subject-modal-header">


<button
type="button"
class="modal-close"
id="closeSubjectModal"
>

<i class="bi bi-x-lg"></i>

</button>


<div class="modal-title">


<div class="modal-icon">

<i class="bi bi-journal-bookmark-fill"></i>

</div>


<div>


<h3>

Registered Subjects

</h3>


<p>

All subjects registered to this student

</p>


<div class="subject-total">

<i class="bi bi-book-half"></i>

<span id="subjectTotal">

0 Registered Subjects

</span>

</div>


</div>


</div>


</div>


<!-- STUDENT INFORMATION -->

<div class="student-information">


<div class="student-info-grid">


<div class="student-info-box">

<div class="student-info-label">

Student No.

</div>


<div
class="student-info-value"
id="modalStudentNo"
>

-

</div>

</div>


<div class="student-info-box">

<div class="student-info-label">

Student Name

</div>


<div
class="student-info-value"
id="modalStudentName"
>

-

</div>

</div>


<div class="student-info-box">

<div class="student-info-label">

Year

</div>


<div
class="student-info-value"
id="modalYear"
>

-

</div>

</div>


<div class="student-info-box">

<div class="student-info-label">

Section

</div>


<div
class="student-info-value"
id="modalSection"
>

-

</div>

</div>


</div>


</div>


<!-- SUBJECT CONTENT -->

<div class="subject-content">


<div class="subject-toolbar">


<div class="subject-toolbar-title">

<i class="bi bi-list-check"></i>

&nbsp;

Subject List

</div>


<div class="subject-search">


<i class="bi bi-search"></i>


<input
type="text"
id="subjectSearch"
placeholder="Search subject..."
autocomplete="off"
>


</div>


</div>


<div
id="subjectList"
class="subject-list"
>

</div>


</div>


<!-- FOOTER -->

<div class="subject-footer">


<div class="subject-visible-count">

<i class="bi bi-info-circle"></i>

&nbsp;

<span id="visibleSubjectCount">

0

</span>

subject(s) displayed

</div>


<button
type="button"
class="btn-done"
id="doneSubjectModal"
>

<i class="bi bi-check-circle"></i>

&nbsp;

Done

</button>


</div>


</div>

</div>


<!-- =====================================================
     CLASS SCHEDULE MODAL
===================================================== -->

<div id="scheduleModal" class="subject-modal-overlay">
    <div class="subject-modal" role="dialog" aria-modal="true">
        <div class="subject-modal-header">
            <button type="button" class="modal-close" id="closeScheduleModal">
                <i class="bi bi-x-lg"></i>
            </button>
            <div class="modal-title">
                <div class="modal-icon"><i class="bi bi-calendar-week"></i></div>
                <div>
                    <h3>Class Schedule</h3>
                    <p>Registered class days and RFID scanning time window</p>
                    <div class="subject-total">
                        <i class="bi bi-clock"></i>
                        <span id="scheduleTotal">0 Registered Days</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="student-information">
            <div class="student-info-grid">
                <div class="student-info-box"><div class="student-info-label">Student No.</div><div class="student-info-value" id="scheduleStudentNo">-</div></div>
                <div class="student-info-box"><div class="student-info-label">Student Name</div><div class="student-info-value" id="scheduleStudentName">-</div></div>
                <div class="student-info-box"><div class="student-info-label">Year</div><div class="student-info-value" id="scheduleYear">-</div></div>
                <div class="student-info-box"><div class="student-info-label">Section</div><div class="student-info-value" id="scheduleSection">-</div></div>
            </div>
        </div>

        <div class="subject-content">
            <div id="scheduleViewList" class="schedule-modal-list"></div>
            <div class="schedule-view-note">
                <i class="bi bi-info-circle"></i>
                RFID scans should only be accepted when the current Philippine time is inside the student's registered day and Time In/Time Out window.
            </div>
        </div>

        <div class="subject-footer">
            <button type="button" class="btn-done" id="doneScheduleModal">
                <i class="bi bi-check-circle"></i>&nbsp; Done
            </button>
        </div>
    </div>
</div>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function ()
    {


        // =================================================
        // STUDENTS FROM PHP
        // =================================================

        const students =
            <?= json_encode(
                $students,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ); ?>;


        // =================================================
        // YEAR LEVELS
        // =================================================

        const yearLevels = [
            '1st Year',
            '2nd Year',
            '3rd Year',
            '4th Year'
        ];


        // =================================================
        // ELEMENTS
        // =================================================

        const yearButtons =
            document.querySelectorAll(
                '.year-button'
            );


        const yearContent =
            document.getElementById(
                'yearContent'
            );


        const studentPanel =
            document.getElementById(
                'studentPanel'
            );


        const studentTableBody =
            document.getElementById(
                'studentTableBody'
            );


        const studentPanelTitle =
            document.getElementById(
                'studentPanelTitle'
            );


        const studentPanelSubtitle =
            document.getElementById(
                'studentPanelSubtitle'
            );


        const closeStudentPanel =
            document.getElementById(
                'closeStudentPanel'
            );


        // =================================================
        // ESCAPE HTML
        // =================================================

        function escapeHtml(value)
        {

            const div =
                document.createElement(
                    'div'
                );


            div.textContent =
                value ?? '';


            return div.innerHTML;

        }


        // =================================================
        // GET YEAR LEVEL
        // =================================================

        function getYearLevel(student)
        {
            const raw = String(student.year_level ?? '').trim();

            if (raw !== '') {
                const value = raw
                    .toLowerCase()
                    .replace(/\s+/g, ' ');

                if (['1', '1st', '1st year', 'first year', 'first'].includes(value)) return '1st Year';
                if (['2', '2nd', '2nd year', 'second year', 'second'].includes(value)) return '2nd Year';
                if (['3', '3rd', '3rd year', 'third year', 'third'].includes(value)) return '3rd Year';
                if (['4', '4th', '4th year', 'fourth year', 'fourth'].includes(value)) return '4th Year';

                if (/\b1(?:st)?\b/.test(value)) return '1st Year';
                if (/\b2(?:nd)?\b/.test(value)) return '2nd Year';
                if (/\b3(?:rd)?\b/.test(value)) return '3rd Year';
                if (/\b4(?:th)?\b/.test(value)) return '4th Year';
            }

            const section = String(student.section || '').toUpperCase();

            const match = section.match(/(?:BSIT[-\s]*)?([1-4])/);

            if (match) {
                switch (parseInt(match[1], 10)) {
                    case 1: return '1st Year';
                    case 2: return '2nd Year';
                    case 3: return '3rd Year';
                    case 4: return '4th Year';
                }
            }

            return '';
        }


        // =================================================
        // FULL NAME
        // =================================================

        function getFullName(student)
        {

            const firstName = student.first_name ?? student.firstName ?? '';
            const middleName = student.middle_Name ?? student.middle_name ?? student.middleName ?? '';
            const lastName = student.last_Name ?? student.last_name ?? student.lastName ?? '';
            const extension = student.extension_name ?? student.extensionName ?? '';

            return (
                firstName + ' ' +
                middleName + ' ' +
                lastName + ' ' +
                extension
            )
            .replace(/\s+/g, ' ')
            .trim();

        }


        // =================================================
        // GET SUBJECTS
        // =================================================

        function getSubjects(subjectData)
        {
            if (subjectData === null || subjectData === undefined) {
                return [];
            }

            const raw = String(subjectData).trim();

            if (!raw) {
                return [];
            }

            try {
                const parsed = JSON.parse(raw);

                if (Array.isArray(parsed)) {
                    return parsed
                        .map(function(item) {
                            return String(item ?? '').trim();
                        })
                        .filter(Boolean);
                }
            } catch (error) {
                // Continue with legacy text formats.
            }

            return raw
                .split(/[,\n|]+/)
                .map(function(item) {
                    return item.trim();
                })
                .filter(Boolean);
        }


        // =================================================
        // GENDER
        // =================================================

        function getGender(student)
        {

            if (
                student.is_Male == 1
            ) {

                return `

                    <span class="badge bg-primary">

                        <i class="bi bi-gender-male"></i>

                        Male

                    </span>

                `;

            }


            if (
                student.is_Female == 1
            ) {

                return `

                    <span class="badge bg-info">

                        <i class="bi bi-gender-female"></i>

                        Female

                    </span>

                `;

            }


            return `

                <span class="badge bg-secondary">

                    Not specified

                </span>

            `;

        }


        // =================================================
        // GET CLASS SCHEDULE (JAVASCRIPT)
        // =================================================

        function getStudentSchedule(scheduleData)
        {
            if (!scheduleData) return [];

            try {
                const parsed = JSON.parse(scheduleData);
                if (Array.isArray(parsed)) return parsed;
            } catch (error) {
                // Old/invalid schedule format.
            }

            return [];
        }

        function formatScheduleTime(time)
        {
            if (!time) return '-';

            const parts = String(time).split(':');
            if (parts.length < 2) return String(time);

            let hour = parseInt(parts[0], 10);
            const minute = parts[1];
            if (Number.isNaN(hour)) return String(time);

            const suffix = hour >= 12 ? 'PM' : 'AM';
            hour = hour % 12;
            if (hour === 0) hour = 12;

            return String(hour).padStart(2, '0') + ':' + minute + ' ' + suffix;
        }


        // =================================================
        // CREATE SUBJECT BUTTON
        // =================================================

        function createSubjectButton(
            student
        )
        {

            const subjects = getSubjects(student.subject);
            const schedule = getStudentSchedule(student.class_schedule);

            return `
                <button type="button" class="btn-view-subject" data-student-id="${escapeHtml(student.stud_id)}">
                    <i class="bi bi-eye-fill"></i> View
                </button>
                <span class="subject-count">${subjects.length}</span>
                <button type="button" class="btn-view-schedule" data-student-id="${escapeHtml(student.stud_id)}">
                    <i class="bi bi-calendar-week"></i> Schedule
                </button>
                <span class="subject-count">${schedule.length}</span>
            `;

        }


        // =================================================
        // LOAD YEAR
        // =================================================

        function loadYear(selectedYear)
        {
            const viewStudents = students.filter(function(student) {
                return getYearLevel(student) === selectedYear;
            });

            let html = '';

            html += `
                <div class="year-header">
                    <div class="year-header-left">
                        <div class="year-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div>
                            <h4>${escapeHtml(selectedYear)}</h4>
                            <small>Registered students by section</small>
                        </div>
                    </div>

                    <div class="year-count">
                        <i class="bi bi-people-fill"></i>
                        ${viewStudents.length}
                        student${viewStudents.length === 1 ? '' : 's'}
                    </div>
                </div>
            `;

            if (viewStudents.length === 0) {
                html += `
                    <div class="no-data">
                        <i class="bi bi-people"></i>
                        <h4>No Students Registered</h4>
                        <p>
                            There are currently no students registered
                            under <strong>${escapeHtml(selectedYear)}</strong>.
                        </p>
                    </div>
                `;

                yearContent.innerHTML = html;
                studentPanel.classList.remove('show');
                return;
            }

            const sections = {};

            viewStudents.forEach(function(student) {
                const section = String(student.section || '').trim() || 'No Section';

                if (!sections[section]) {
                    sections[section] = [];
                }

                sections[section].push(student);
            });

            const sortedSections = Object.keys(sections).sort(function(a, b) {
                return a.localeCompare(b, undefined, {
                    numeric: true,
                    sensitivity: 'base'
                });
            });

            html += `
                <div class="year-level-container">
                    <div class="year-level-heading">
                        <div class="year-level-icon">
                            <i class="bi bi-collection-fill"></i>
                        </div>
                        <div>
                            <h5>${escapeHtml(selectedYear)} Sections</h5>
                            <small>
                                ${viewStudents.length}
                                student${viewStudents.length === 1 ? '' : 's'}
                                in this year
                            </small>
                        </div>
                    </div>

                    <div class="section-grid">
            `;

            sortedSections.forEach(function(section) {
                const count = sections[section].length;

                html += `
                    <div
                        class="section-card"
                        data-year="${escapeHtml(selectedYear)}"
                        data-section="${escapeHtml(section)}"
                    >
                        <div class="section-top">
                            <div class="section-icon">
                                <i class="bi bi-people-fill"></i>
                            </div>

                            <div class="section-count">
                                <i class="bi bi-person-fill"></i>
                                ${count}
                            </div>
                        </div>

                        <div class="section-name">
                            ${escapeHtml(section)}
                        </div>

                        <div class="section-description">
                            View students
                            <i class="bi bi-arrow-right"></i>
                        </div>
                    </div>
                `;
            });

            html += `
                    </div>
                </div>
            `;

            yearContent.innerHTML = html;
            attachSectionEvents();
        }


        // =================================================
        // SECTION CLICK
        // =================================================

        function attachSectionEvents()
        {

            const cards =
                document.querySelectorAll(
                    '.section-card'
                );


            cards.forEach(
                function(card)
                {

                    card.addEventListener(
                        'click',
                        function()
                        {

                            cards.forEach(
                                function(other)
                                {

                                    other.classList.remove(
                                        'active'
                                    );

                                }
                            );


                            card.classList.add(
                                'active'
                            );


                            showStudents(
                                card.dataset.year,
                                card.dataset.section
                            );

                        }
                    );

                }
            );

        }


        // =================================================
        // SHOW STUDENTS
        // =================================================

        function showStudents(
            year,
            section
        )
        {

            const filteredStudents =
                students.filter(
                    function(student)
                    {

                        return (

                            getYearLevel(student)
                            ===
                            year

                            &&

                            String(
                                student.section || ''
                            ).trim()
                            ===
                            String(
                                section
                            ).trim()

                        );

                    }
                );


            studentTableBody.innerHTML =
                '';


            // =================================================
            // HEADER
            // =================================================

            studentPanelTitle.textContent =
                section;


            studentPanelSubtitle.textContent =

                year +
                ' • ' +
                filteredStudents.length +
                ' student' +
                (
                    filteredStudents.length === 1
                        ? ''
                        : 's'
                );


            // =================================================
            // TABLE ROWS
            // =================================================

            filteredStudents.forEach(
                function(student)
                {

                    const tr =
                        document.createElement(
                            'tr'
                        );


                    tr.innerHTML = `

                        <td>

                            ${escapeHtml(
                                student.stud_id
                            )}

                        </td>


                        <td>

                            <strong>

                                ${escapeHtml(
                                    student.student_No
                                )}

                            </strong>

                        </td>


                        <td>

                            ${escapeHtml(
                                getFullName(student)
                            )}

                        </td>


                        <td>

                            ${escapeHtml(
                                year
                            )}

                        </td>


                        <td>

                            ${escapeHtml(
                                student.section || '-'
                            )}

                        </td>


                        <td>

                            ${createSubjectButton(
                                student
                            )}

                        </td>


                        <td>

                            <span class="badge bg-dark">

                                ${escapeHtml(
                                    student.student_RFID_Code || '-'
                                )}

                            </span>

                        </td>


                        <td>

                            ${getGender(student)}

                        </td>


                        <td>

                            <a
                                href="delete.php?id=${encodeURIComponent(
                                    student.stud_id
                                )}"
                                class="btn btn-danger btn-sm"
                                onclick="
                                    return confirm(
                                        'Delete this student?'
                                    )
                                "
                            >

                                <i class="bi bi-trash"></i>

                            </a>

                        </td>

                    `;


                    studentTableBody.appendChild(
                        tr
                    );

                }
            );


            studentPanel.classList.add(
                'show'
            );


            // =================================================
            // SUBJECT BUTTON EVENTS
            // =================================================

            attachSubjectEvents();
            attachScheduleEvents();


            // =================================================
            // SCROLL
            // =================================================

            setTimeout(
                function()
                {

                    studentPanel.scrollIntoView(
                        {
                            behavior: 'smooth',
                            block: 'start'
                        }
                    );

                },
                100
            );

        }


        // =================================================
        // YEAR BUTTON EVENTS
        // =================================================

        yearButtons.forEach(
            function(button)
            {

                button.addEventListener(
                    'click',
                    function()
                    {

                        // Reload the whole page so the year selection always
                        // reads the latest database information.
                        window.location.href =
                            '?year=' + encodeURIComponent(button.dataset.year);

                    }
                );

            }
        );


        // =================================================
        // CLOSE STUDENT PANEL
        // =================================================

        closeStudentPanel.addEventListener(
            'click',
            function()
            {

                studentPanel.classList.remove(
                    'show'
                );


                document
                    .querySelectorAll(
                        '.section-card'
                    )
                    .forEach(
                        function(card)
                        {

                            card.classList.remove(
                                'active'
                            );

                        }
                    );

            }
        );


        // =================================================
        // SUBJECT MODAL
        // =================================================

        const subjectModal =
            document.getElementById(
                'subjectModal'
            );


        const closeSubjectModal =
            document.getElementById(
                'closeSubjectModal'
            );


        const doneSubjectModal =
            document.getElementById(
                'doneSubjectModal'
            );


        const subjectSearch =
            document.getElementById(
                'subjectSearch'
            );


        const subjectList =
            document.getElementById(
                'subjectList'
            );


        const subjectTotal =
            document.getElementById(
                'subjectTotal'
            );


        const visibleSubjectCount =
            document.getElementById(
                'visibleSubjectCount'
            );


        const modalStudentNo =
            document.getElementById(
                'modalStudentNo'
            );


        const modalStudentName =
            document.getElementById(
                'modalStudentName'
            );


        const modalYear =
            document.getElementById(
                'modalYear'
            );


        const modalSection =
            document.getElementById(
                'modalSection'
            );


        let currentSubjects = [];

        const scheduleModal = document.getElementById('scheduleModal');
        const closeScheduleModal = document.getElementById('closeScheduleModal');
        const doneScheduleModal = document.getElementById('doneScheduleModal');
        const scheduleViewList = document.getElementById('scheduleViewList');
        const scheduleTotal = document.getElementById('scheduleTotal');
        const scheduleStudentNo = document.getElementById('scheduleStudentNo');
        const scheduleStudentName = document.getElementById('scheduleStudentName');
        const scheduleYear = document.getElementById('scheduleYear');
        const scheduleSection = document.getElementById('scheduleSection');


        // =================================================
        // ATTACH SUBJECT EVENTS
        // =================================================

        function attachSubjectEvents()
        {

            const buttons =
                document.querySelectorAll(
                    '.btn-view-subject'
                );


            buttons.forEach(
                function(button)
                {

                    button.addEventListener(
                        'click',
                        function(event)
                        {

                            event.stopPropagation();


                            const studentId =
                                button.dataset.studentId;


                            const student =
                                students.find(
                                    function(item)
                                    {

                                        return String(
                                            item.stud_id
                                        )
                                        ===
                                        String(
                                            studentId
                                        );

                                    }
                                );


                            if (
                                student
                            ) {

                                openSubjectModal(
                                    student
                                );

                            }

                        }
                    );

                }
            );

        }


        // =================================================
        // ATTACH CLASS SCHEDULE EVENTS
        // =================================================

        function attachScheduleEvents()
        {
            document.querySelectorAll('.btn-view-schedule').forEach(function(button) {
                button.addEventListener('click', function(event) {
                    event.stopPropagation();
                    const student = students.find(function(item) {
                        return String(item.stud_id) === String(button.dataset.studentId);
                    });
                    if (student) openScheduleModal(student);
                });
            });
        }

        function openScheduleModal(student)
        {
            const schedule = getStudentSchedule(student.class_schedule);
            scheduleStudentNo.textContent = student.student_No || '-';
            scheduleStudentName.textContent = getFullName(student) || '-';
            scheduleYear.textContent = getYearLevel(student) || '-';
            scheduleSection.textContent = student.section || '-';
            scheduleTotal.textContent = schedule.length + ' Registered Day' + (schedule.length === 1 ? '' : 's');
            scheduleViewList.innerHTML = '';

            const order = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            schedule.sort(function(a,b){ return order.indexOf(a.day) - order.indexOf(b.day); });

            if (schedule.length === 0) {
                scheduleViewList.innerHTML = '<div class="no-data"><i class="bi bi-calendar-x"></i><strong>No Class Schedule Registered</strong></div>';
            } else {
                schedule.forEach(function(item) {
                    const row = document.createElement('div');
                    row.className = 'schedule-view-item';
                    row.innerHTML = `
                        <div class="schedule-view-day"><i class="bi bi-calendar3"></i> ${escapeHtml(item.day || '-')}</div>
                        <div class="schedule-view-time"><i class="bi bi-clock"></i> ${escapeHtml(formatScheduleTime(item.time_in))} - ${escapeHtml(formatScheduleTime(item.time_out))}</div>
                    `;
                    scheduleViewList.appendChild(row);
                });
            }

            scheduleModal.classList.add('show');
            document.body.classList.add('subject-modal-open');
        }

        function closeScheduleViewer()
        {
            scheduleModal.classList.remove('show');
            document.body.classList.remove('subject-modal-open');
        }

        closeScheduleModal.addEventListener('click', closeScheduleViewer);
        doneScheduleModal.addEventListener('click', closeScheduleViewer);
        scheduleModal.addEventListener('click', function(event) {
            if (event.target === scheduleModal) closeScheduleViewer();
        });


        // =================================================
        // OPEN SUBJECT MODAL
        // =================================================

        function openSubjectModal(
            student
        )
        {

            currentSubjects =
                getSubjects(
                    student.subject
                );


            modalStudentNo.textContent =
                student.student_No || '-';


            modalStudentName.textContent =
                getFullName(
                    student
                ) || '-';


            modalYear.textContent =
                getYearLevel(
                    student
                ) || '-';


            modalSection.textContent =
                student.section || '-';


            subjectTotal.textContent =

                currentSubjects.length +
                ' Registered Subject' +
                (
                    currentSubjects.length === 1
                        ? ''
                        : 's'
                );


            subjectSearch.value =
                '';


            displaySubjects(
                currentSubjects
            );


            subjectModal.classList.add(
                'show'
            );


            document.body.classList.add(
                'subject-modal-open'
            );


            setTimeout(
                function()
                {

                    subjectSearch.focus();

                },
                150
            );

        }


        // =================================================
        // DISPLAY SUBJECTS
        // =================================================

        function displaySubjects(
            subjects
        )
        {

            subjectList.innerHTML =
                '';


            if (
                subjects.length === 0
            ) {

                subjectList.innerHTML = `

                    <div class="no-data">

                        <i class="bi bi-journal-x"></i>

                        <strong>

                            No Subjects Registered

                        </strong>

                    </div>

                `;


                visibleSubjectCount.textContent =
                    '0';


                return;

            }


            subjects.forEach(
                function(subject, index)
                {

                    const item =
                        document.createElement(
                            'div'
                        );


                    item.className =
                        'subject-item';


                    item.dataset.subject =
                        String(
                            subject
                        ).toLowerCase();


                    item.innerHTML = `

                        <div class="subject-number">

                            ${index + 1}

                        </div>


                        <div class="subject-book">

                            <i class="bi bi-book-fill"></i>

                        </div>


                        <div class="subject-name">

                            ${escapeHtml(
                                subject
                            )}

                        </div>

                    `;


                    subjectList.appendChild(
                        item
                    );

                }
            );


            visibleSubjectCount.textContent =
                subjects.length;

        }


        // =================================================
        // SUBJECT SEARCH
        // =================================================

        subjectSearch.addEventListener(
            'input',
            function()
            {

                const search =
                    subjectSearch.value
                        .toLowerCase()
                        .trim();


                const items =
                    subjectList.querySelectorAll(
                        '.subject-item'
                    );


                let visible = 0;


                items.forEach(
                    function(item)
                    {

                        if (
                            item.dataset.subject
                                .includes(
                                    search
                                )
                        ) {

                            item.style.display =
                                'flex';

                            visible++;

                        }
                        else {

                            item.style.display =
                                'none';

                        }

                    }
                );


                visibleSubjectCount.textContent =
                    visible;

            }
        );


        // =================================================
        // CLOSE SUBJECT MODAL
        // =================================================

        function closeSubjectViewer()
        {

            subjectModal.classList.remove(
                'show'
            );


            document.body.classList.remove(
                'subject-modal-open'
            );


            subjectSearch.value =
                '';

        }


        closeSubjectModal.addEventListener(
            'click',
            closeSubjectViewer
        );


        doneSubjectModal.addEventListener(
            'click',
            closeSubjectViewer
        );


        subjectModal.addEventListener(
            'click',
            function(event)
            {

                if (
                    event.target ===
                    subjectModal
                ) {

                    closeSubjectViewer();

                }

            }
        );


        // =================================================
        // ESC KEY
        // =================================================

        document.addEventListener(
            'keydown',
            function(event)
            {

                if (
                    event.key === 'Escape'
                ) {

                    if (subjectModal.classList.contains('show')) {
                        closeSubjectViewer();
                    }
                    if (scheduleModal.classList.contains('show')) {
                        closeScheduleViewer();
                    }

                }

            }
        );


        // =================================================
        // INITIAL LOAD
        // =================================================

        loadYear(
            <?= json_encode($selectedYearFromRequest); ?>
        );


    }
);

</script>


</body>

</html>