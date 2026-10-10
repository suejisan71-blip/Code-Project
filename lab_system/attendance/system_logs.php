<?php

include '../config/db.php';

session_start();

date_default_timezone_set('Asia/Manila');


if(!isset($_SESSION['user'])){

    header("Location: ../index.php");
    exit();

}


$recordsPerPage = 15;

$currentPage = isset($_GET['page'])
    ? max(1, (int)$_GET['page'])
    : 1;

$countResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM system_logs
");

$totalLogs = 0;

if ($countResult) {
    $countRow = $countResult->fetch_assoc();
    $totalLogs = (int)$countRow['total'];
}

$totalPages = max(1, (int)ceil($totalLogs / $recordsPerPage));

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $recordsPerPage;

$query = "
    SELECT *
    FROM system_logs
    ORDER BY log_id DESC
    LIMIT $recordsPerPage OFFSET $offset
";

$result = $conn->query($query);

$dbError = '';

if (!$result) {
    $dbError = $conn->error;
}

// Display helper: localhost IPv6 (::1) is the local machine address.
// Existing logs keep their saved IP values.

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>NEUST System Logs</title>


<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">


<style>

*{
box-sizing:border-box;
}


body{

margin:0;

min-height:100vh;

font-family:'Segoe UI',sans-serif;

background:

linear-gradient(

135deg,

#001f3f,

#0056b3,

#38bdf8

);

}



/* SIDEBAR */

.sidebar{

width:260px;

height:100vh;

position:fixed;

background:rgba(15,23,42,.88);

backdrop-filter:blur(15px);

color:white;

padding:25px 15px;

}



.logo{

width:85px;

height:85px;

background:white;

padding:8px;

border-radius:50%;

object-fit:contain;

display:block;

margin:auto;

}



.sidebar h4{

text-align:center;

font-weight:800;

margin:15px 0 30px;

}



.sidebar a{

display:flex;

align-items:center;

gap:12px;

padding:13px 18px;

margin:8px 0;

border-radius:14px;

color:#dbeafe;

text-decoration:none;

transition:.3s;

}



.sidebar a:hover,

.sidebar a.active{

background:linear-gradient(90deg,#2563eb,#38bdf8);

color:white;

}



/* TOPBAR */

.topbar{

margin-left:260px;

height:75px;

display:flex;

align-items:center;

justify-content:space-between;

padding:0 35px;

background:rgba(255,255,255,.15);

backdrop-filter:blur(15px);

color:white;

}



/* CONTENT */

.content{

margin-left:260px;

padding:40px;

}



.card-box{

background:rgba(255,255,255,.18);

backdrop-filter:blur(18px);

border-radius:25px;

padding:30px;

box-shadow:0 25px 60px rgba(0,0,0,.25);

color:white;

}



/* TABLE */

.table{

color:#000000;

}

.table tbody,
.table tbody tr,
.table tbody td,
.table tbody th{

color:#000000 !important;

}



.table thead{

background:#0056b3;

color:white;

}



.table tbody tr:hover{

background:rgba(255,255,255,.12);

}



.badge{

padding:10px 15px;

border-radius:15px;

}





/* =====================================================
   ENHANCED SYSTEM LOGS DESIGN
===================================================== */

.logs-header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:25px}
.logs-title-wrap{display:flex;align-items:center;gap:15px}
.logs-title-icon{width:52px;height:52px;display:flex;align-items:center;justify-content:center;border-radius:16px;background:linear-gradient(135deg,#2563eb,#38bdf8);box-shadow:0 10px 25px rgba(37,99,235,.25);font-size:22px}
.logs-title-wrap h3{margin:0;font-size:25px;font-weight:800}
.logs-title-wrap p{margin:4px 0 0;color:#dbeafe;font-size:13px}
.audit-badge{display:inline-flex;align-items:center;gap:8px;padding:10px 15px;border-radius:14px;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.14);font-size:13px;font-weight:700;white-space:nowrap}
.log-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:22px}
.summary-box{display:flex;align-items:center;gap:14px;padding:18px;border-radius:18px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.12)}
.summary-icon{width:45px;height:45px;min-width:45px;display:flex;align-items:center;justify-content:center;border-radius:13px;background:rgba(255,255,255,.12);font-size:19px}
.summary-box strong{display:block;font-size:20px;font-weight:800}
.summary-box span{color:#dbeafe;font-size:12px}
.logs-table-wrap{overflow:hidden;border-radius:18px;border:1px solid rgba(255,255,255,.13);background:rgba(0,31,63,.25)}
.logs-table{margin:0!important}
.logs-table thead th{border:0!important;padding:16px 15px!important;background:rgba(0,31,63,.75)!important;color:#dbeafe!important;font-size:11px;text-transform:uppercase;letter-spacing:.7px;white-space:nowrap}
.logs-table tbody td{padding:16px 15px!important;border-color:rgba(0,0,0,.10)!important;vertical-align:middle;color:#000000!important}
.logs-table tbody,
.logs-table tbody tr,
.logs-table tbody td,
.logs-table tbody th,
.logs-table tbody td span,
.logs-table tbody td div,
.logs-table tbody td a,
.logs-table tbody td i{color:#000000!important}
.logs-table tbody tr{transition:.2s ease}
.logs-table tbody tr:hover{background:rgba(255,255,255,.08)!important}
.log-id{display:inline-flex;align-items:center;justify-content:center;min-width:42px;padding:6px 9px;border-radius:9px;background:rgba(56,189,248,.13);color:#bae6fd;font-size:12px;font-weight:800}
.user-cell{display:flex;align-items:center;gap:10px}
.user-avatar{width:36px;height:36px;min-width:36px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:linear-gradient(135deg,#2563eb,#38bdf8);color:white;font-weight:800;font-size:13px}
.user-name{font-weight:700}
.activity-cell{display:flex;align-items:center;gap:9px;font-weight:600}
.activity-icon{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:9px;background:rgba(255,255,255,.10);color:#bae6fd}
.ip-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:9px;background:rgba(255,255,255,.09);color:#e0f2fe;font-family:Consolas,monospace;font-size:12px}
.date-cell{color:#e0f2fe;font-weight:600}
.time-cell{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:9px;background:rgba(255,255,255,.09);color:#e0f2fe;font-size:12px;font-weight:700}
.empty-logs{padding:55px 20px!important;text-align:center}
.empty-logs-icon{width:70px;height:70px;margin:0 auto 15px;display:flex;align-items:center;justify-content:center;border-radius:20px;background:rgba(255,255,255,.09);font-size:28px;color:#bae6fd}
.empty-logs h5{margin-bottom:6px;font-weight:800}
.empty-logs p{margin:0;color:#dbeafe;font-size:13px}
.pagination-area{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-top:22px;flex-wrap:wrap}
.pagination-info{color:#dbeafe;font-size:13px;font-weight:600}
.pagination{margin:0;gap:6px}
.pagination .page-item .page-link{min-width:39px;height:39px;display:flex;align-items:center;justify-content:center;border-radius:11px!important;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.08);color:white;font-size:13px;font-weight:800;box-shadow:none}
.pagination .page-item .page-link:hover{background:rgba(255,255,255,.18);color:white;border-color:rgba(255,255,255,.22)}
.pagination .page-item.active .page-link{background:linear-gradient(135deg,#2563eb,#0ea5e9);border-color:transparent;color:white;box-shadow:0 7px 18px rgba(14,165,233,.22)}
.pagination .page-item.disabled .page-link{opacity:.38;cursor:not-allowed}
.db-error{margin-bottom:20px;padding:14px 17px;border-radius:13px;background:rgba(220,38,38,.16);border:1px solid rgba(248,113,113,.35);color:#fee2e2}
@media(max-width:900px){.log-summary{grid-template-columns:1fr}.logs-header{align-items:flex-start;flex-direction:column}.pagination-area{align-items:flex-start;flex-direction:column}}

</style>

</head>


<body>


<div class="sidebar">


<img src="../assets/neust_logo.png" class="logo">


<h4>

NEUST Lab System

</h4>



<a href="../dashboard.php">

<i class="bi bi-grid-fill"></i>

Dashboard

</a>



<a href="../students/add.php">

<i class="bi bi-person-plus-fill"></i>

Add Student

</a>



<a href="../students/list.php">

<i class="bi bi-people-fill"></i>

Student List

</a>



<a href="system_logs.php" class="active">

<i class="bi bi-clock-history"></i>

System Logs

</a>



<a href="records.php">

<i class="bi bi-calendar-check-fill"></i>

Records

</a>



<a href="../logout.php">

<i class="bi bi-box-arrow-right"></i>

Logout

</a>


</div>




<div class="topbar">


<strong>

System Logs

</strong>


<div>

<i class="bi bi-person-circle"></i>

<?= $_SESSION['user']['first_name']; ?>

</div>


</div>




<div class="content">


<div class="card-box">

<?php if($dbError !== ''): ?>
<div class="db-error">
<strong><i class="bi bi-exclamation-triangle-fill"></i> Database Error:</strong>
<?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php endif; ?>

<div class="logs-header">
<div class="logs-title-wrap">
<div class="logs-title-icon"><i class="bi bi-shield-check"></i></div>
<div>
<h3>System Activity Logs</h3>
<p>Monitor and review every recorded system activity.</p>
</div>
</div>
<div class="audit-badge"><i class="bi bi-clock-history"></i> Audit Trail</div>
</div>

<div class="log-summary">

<div class="summary-box">
<div class="summary-icon"><i class="bi bi-journal-text"></i></div>
<div>
<strong><?= number_format($totalLogs); ?></strong>
<span>Total Log Records</span>
</div>
</div>

<div class="summary-box">
<div class="summary-icon"><i class="bi bi-file-earmark-text"></i></div>
<div>
<strong><?= $totalLogs > 0 ? $currentPage : 0; ?></strong>
<span>Current Page</span>
</div>
</div>

<div class="summary-box">
<div class="summary-icon"><i class="bi bi-stack"></i></div>
<div>
<strong><?= $totalLogs > 0 ? $totalPages : 0; ?></strong>
<span>Total Pages</span>
</div>
</div>

</div>

<div class="logs-table-wrap">
<div class="table-responsive">
<table class="table table-hover align-middle logs-table">

<thead>
<tr>
<th>ID</th>
<th>User</th>
<th>Action Performed</th>
<th>IP Address</th>
<th>Date</th>
<th>Time</th>
</tr>
</thead>

<tbody>

<?php if($result && $result->num_rows > 0): ?>

<?php while($row=$result->fetch_assoc()): ?>

<?php
$username = trim((string)($row['username'] ?? ''));
$activity = trim((string)($row['activity'] ?? ''));
$initial = $username !== '' ? strtoupper(substr($username,0,1)) : '?';
?>

<tr>

<td>
<span class="log-id">
#<?= htmlspecialchars($row['log_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</span>
</td>

<td>
<div class="user-cell">
<div class="user-avatar">
<?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
</div>
<div class="user-name">
<?= htmlspecialchars($username !== '' ? $username : 'Unknown User', ENT_QUOTES, 'UTF-8'); ?>
</div>
</div>
</td>

<td>
<div class="activity-cell">
<span class="activity-icon"><i class="bi bi-lightning-charge-fill"></i></span>
<span>
<?= htmlspecialchars($activity !== '' ? $activity : 'No activity recorded', ENT_QUOTES, 'UTF-8'); ?>
</span>
</div>
</td>

<td>
<span class="ip-badge">
<i class="bi bi-globe2"></i>
<?= !empty($row['ip_address']) ? htmlspecialchars($row['ip_address'], ENT_QUOTES, 'UTF-8') : 'No IP Recorded'; ?>
</span>
</td>

<td>
<span class="date-cell">
<i class="bi bi-calendar3"></i>
<?= htmlspecialchars($row['log_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
</span>
</td>

<td>
<span class="time-cell">
<i class="bi bi-clock"></i>
<?= !empty($row['log_time']) ? htmlspecialchars(date('h:i:s A',strtotime($row['log_time'])), ENT_QUOTES, 'UTF-8') : 'No Time Recorded'; ?>
</span>
</td>

</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>
<td colspan="6" class="empty-logs">
<div class="empty-logs-icon"><i class="bi bi-journal-x"></i></div>
<h5>No System Logs Found</h5>
<p>There are currently no recorded system activities.</p>
</td>
</tr>

<?php endif; ?>

</tbody>
</table>
</div>
</div>

<?php if($totalLogs > 0): ?>

<?php
$startRecord = $offset + 1;
$endRecord = min($offset + $recordsPerPage,$totalLogs);
?>

<div class="pagination-area">

<div class="pagination-info">
Showing <strong><?= $startRecord; ?></strong> –
<strong><?= $endRecord; ?></strong> of
<strong><?= number_format($totalLogs); ?></strong> log records
</div>

<?php if($totalPages > 1): ?>

<nav aria-label="System logs pagination">
<ul class="pagination">

<li class="page-item <?= $currentPage <= 1 ? 'disabled' : ''; ?>">
<a class="page-link" href="?page=<?= max(1,$currentPage-1); ?>">
<i class="bi bi-chevron-left"></i>
</a>
</li>

<?php
$paginationStart = max(1,$currentPage-2);
$paginationEnd = min($totalPages,$currentPage+2);
?>

<?php if($paginationStart > 1): ?>
<li class="page-item"><a class="page-link" href="?page=1">1</a></li>
<?php if($paginationStart > 2): ?>
<li class="page-item disabled"><span class="page-link">...</span></li>
<?php endif; ?>
<?php endif; ?>

<?php for($page=$paginationStart;$page<=$paginationEnd;$page++): ?>
<li class="page-item <?= $page === $currentPage ? 'active' : ''; ?>">
<a class="page-link" href="?page=<?= $page; ?>"><?= $page; ?></a>
</li>
<?php endfor; ?>

<?php if($paginationEnd < $totalPages): ?>
<?php if($paginationEnd < $totalPages-1): ?>
<li class="page-item disabled"><span class="page-link">...</span></li>
<?php endif; ?>
<li class="page-item"><a class="page-link" href="?page=<?= $totalPages; ?>"><?= $totalPages; ?></a></li>
<?php endif; ?>

<li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : ''; ?>">
<a class="page-link" href="?page=<?= min($totalPages,$currentPage+1); ?>">
<i class="bi bi-chevron-right"></i>
</a>
</li>

</ul>
</nav>

<?php endif; ?>

</div>

<?php endif; ?>

</div>

</div>

</body>

</html>
