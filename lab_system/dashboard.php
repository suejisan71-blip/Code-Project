<?php
/**
 * NEUST Lab System - Unified Dashboard with Real-Time Analytics
 */

session_start();
include 'config/db.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

// AJAX handler for Real-time Analytics polling
if (isset($_GET['action']) && $_GET['action'] === 'get_analytics') {
    header('Content-Type: application/json');
    $today = date("Y-m-d");

    // Total registered students
    $student_result = $conn->query("SELECT COUNT(*) AS total FROM students");
    $total_students = $student_result ? (int)$student_result->fetch_assoc()['total'] : 0;

    // Total registered users
    $user_result = $conn->query("SELECT COUNT(*) AS total FROM users");
    $total_users = $user_result ? (int)$user_result->fetch_assoc()['total'] : 0;

    // Today's attendance analytics
    $att_today_res = $conn->query("SELECT COUNT(*) AS total FROM attendance WHERE date_log='$today'");
    $total_today_attendance = $att_today_res ? (int)$att_today_res->fetch_assoc()['total'] : 0;

    // Attendance Percentage Rate
    $attendance_rate = $total_students > 0 ? round(($total_today_attendance / $total_students) * 100, 1) : 0;

    // System Status Check
    $system_status = $conn && $conn->ping() ? "ONLINE" : "OFFLINE";

    echo json_encode([
        'total_students' => $total_students,
        'total_users' => $total_users,
        'total_today_attendance' => $total_today_attendance,
        'attendance_rate' => $attendance_rate,
        'system_status' => $system_status,
        'server_time' => date('h:i:s A')
    ]);
    exit();
}

$today = date("Y-m-d");

// Initial data load for rendering
$student_result = $conn->query("SELECT COUNT(*) AS total FROM students");
$total_students = $student_result ? (int)$student_result->fetch_assoc()['total'] : 0;

$user_result = $conn->query("SELECT COUNT(*) AS total FROM users");
$total_users = $user_result ? (int)$user_result->fetch_assoc()['total'] : 0;

$att_today_res = $conn->query("SELECT COUNT(*) AS total FROM attendance WHERE date_log='$today'");
$total_today_attendance = $att_today_res ? (int)$att_today_res->fetch_assoc()['total'] : 0;

$attendance_rate = $total_students > 0 ? round(($total_today_attendance / $total_students) * 100, 1) : 0;
$system_status = $conn && $conn->ping() ? "ONLINE" : "OFFLINE";

$displayUser = trim(($_SESSION['user']['first_name'] ?? 'Admin') . ' ' . ($_SESSION['user']['last_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEUST Lab System | Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #38bdf8;
            --navy: #0f172a;
            --border: rgba(255, 255, 255, 0.18);
        }

        * {
            box-sizing: border-box;
            transition: none !important; /* Disable unwanted movement transitions */
            transform: none !important;  /* Keep all elements completely still */
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: radial-gradient(circle at top right, rgba(56, 189, 248, 0.25), transparent 35%),
                        linear-gradient(135deg, #001f3f 0%, #0056b3 48%, #38bdf8 100%);
            color: #ffffff;
        }

        /* Fixed Sidebar */
        .sidebar {
            width: 260px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(20px);
            color: white;
            padding: 25px 15px;
            border-right: 1px solid var(--border);
            z-index: 1000;
        }

        .logo {
            width: 75px;
            height: 75px;
            background: #ffffff;
            padding: 6px;
            border-radius: 50%;
            object-fit: contain;
            display: block;
            margin: 0 auto 10px;
        }

        .brand-title {
            text-align: center;
            font-weight: 800;
            margin: 10px 0 20px;
            font-size: 16px;
            letter-spacing: 0.5px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            margin: 6px 0;
            border-radius: 12px;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }

        .sidebar a:hover, .sidebar a.active {
            background: linear-gradient(90deg, #2563eb, #38bdf8);
            color: #ffffff;
        }

        /* Topbar Header */
        .topbar {
            margin-left: 260px;
            height: 75px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(15px);
            border-bottom: 1px solid var(--border);
            color: white;
        }

        .content {
            margin-left: 260px;
            padding: 35px;
        }

        .welcome-header {
            font-size: 28px;
            font-weight: 800;
        }

        /* Static Modern Analytics Cards */
        .stat-card {
            height: 140px;
            padding: 22px;
            border-radius: 18px;
            color: white;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }

        .stat-card.blue { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); }
        .stat-card.green { background: linear-gradient(135deg, #047857 0%, #10b981 100%); }
        .stat-card.purple { background: linear-gradient(135deg, #6d28d9 0%, #8b5cf6 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #b45309 0%, #f59e0b 100%); }

        .stat-title {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            opacity: 0.9;
        }

        .stat-number {
            font-size: 34px;
            font-weight: 800;
            margin-top: 8px;
            line-height: 1;
        }

        /* Live Badge Indicator */
        .live-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            background-color: #22c55e;
            border-radius: 50%;
        }

        /* Quick Actions Container */
        .quick-card {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px;
            color: white;
            margin-top: 25px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }

        .quick-card a {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #ffffff;
            color: #0056b3;
            text-align: center;
            padding: 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            border: 1px solid #e2e8f0;
        }

        .quick-card a:hover {
            background: #f0f9ff;
            color: #0284c7;
        }

        /* Time & Date Display Box */
        .live-clock-box {
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 15px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <img src="assets/neust_logo.png" class="logo" alt="NEUST Logo">
    <h4 class="brand-title">NEUST Lab System</h4>
    <hr style="border-color: var(--border); margin: 15px 0;">
    <a href="dashboard.php" class="active"><i class="bi bi-grid-fill"></i> Dashboard</a>
    <a href="students/add.php"><i class="bi bi-person-plus-fill"></i> Add Student</a>
    <a href="students/list.php"><i class="bi bi-people-fill"></i> Student List</a>
    <a href="attendance/system_logs.php"><i class="bi bi-clock-history"></i> System Logs</a>
    <a href="attendance/records.php"><i class="bi bi-calendar-check-fill"></i> Records</a>
    <a href="logout.php" style="color: #fca5a5;"><i class="bi bi-box-arrow-right"></i> Logout</a>
</aside>

<header class="topbar">
    <div class="d-flex align-items-center gap-3">
        <strong class="fs-5 m-0"><i class="bi bi-speedometer2"></i> System Analytics</strong>
        <span class="live-badge"><span class="pulse-dot"></span> LIVE SYSTEM FEED</span>
    </div>
    <div class="fw-semibold"><i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($displayUser); ?></div>
</header>

<main class="content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="welcome-header">Welcome back, <?= htmlspecialchars($_SESSION['user']['first_name'] ?? 'User'); ?> 👋</div>
            <div class="opacity-75 small">NEUST Laboratory Real-Time Analytical Monitoring</div>
        </div>
        <div class="live-clock-box">
            <div class="text-end">
                <div id="liveClock" class="fw-bold fs-5 text-info"><?= date('h:i:s A'); ?></div>
                <div class="small opacity-75"><?= date('l, M d, Y'); ?></div>
            </div>
        </div>
    </div>

    <!-- Analytics Section -->
    <div class="row g-3">
        <div class="col-md-3">
            <div class="stat-card blue">
                <div class="stat-title"><i class="bi bi-people-fill me-1"></i> Total Students</div>
                <div class="stat-number" id="statStudents"><?= $total_students; ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card green">
                <div class="stat-title"><i class="bi bi-person-badge-fill me-1"></i> System Users</div>
                <div class="stat-number" id="statUsers"><?= $total_users; ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card purple">
                <div class="stat-title"><i class="bi bi-calendar-check-fill me-1"></i> Today's Logs</div>
                <div class="stat-number">
                    <span id="statAttendance"><?= $total_today_attendance; ?></span>
                    <span class="fs-6 fw-normal opacity-75">(<span id="statRate"><?= $attendance_rate ?></span>%)</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card orange">
                <div class="stat-title"><i class="bi bi-cpu-fill me-1"></i> System Status</div>
                <div class="stat-number" id="statStatus"><?= $system_status; ?></div>
            </div>
        </div>
    </div>

    <!-- Navigation Shortcuts -->
    <div class="quick-card">
        <h5 class="fw-bold mb-3"><i class="bi bi-lightning-charge-fill me-1 text-warning"></i> Quick Navigation</h5>
        <div class="row g-3">
            <div class="col-md-4">
                <a href="students/add.php"><i class="bi bi-person-plus-fill fs-5"></i> Register New Student</a>
            </div>
            <div class="col-md-4">
                <a href="students/list.php"><i class="bi bi-people-fill fs-5"></i> Manage Registered Students</a>
            </div>
            <div class="col-md-4">
                <a href="attendance/records.php"><i class="bi bi-calendar-check-fill fs-5"></i> View Attendance Records</a>
            </div>
        </div>
    </div>
</main>

<script>
// Real-time Analytics Polling Function
function updateAnalytics() {
    fetch('dashboard.php?action=get_analytics')
        .then(response => response.json())
        .then(data => {
            document.getElementById('statStudents').textContent = data.total_students;
            document.getElementById('statUsers').textContent = data.total_users;
            document.getElementById('statAttendance').textContent = data.total_today_attendance;
            document.getElementById('statRate').textContent = data.attendance_rate;
            document.getElementById('statStatus').textContent = data.system_status;
            document.getElementById('liveClock').textContent = data.server_time;
        })
        .catch(err => console.error('Analytics Fetch Error:', err));
}

// Poll real-time data every 3 seconds
setInterval(updateAnalytics, 3000);
</script>

</body>
</html>