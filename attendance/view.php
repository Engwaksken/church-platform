<?php
require_once '../config/config.php';
requireLogin();

$date = san($_GET['date'] ?? '');
$type = san($_GET['type'] ?? '');

$records = mysqli_query($conn, "
    SELECT 
        a.*,
        m.full_name AS member_name,
        m.member_no,
        m.department
    FROM attendance a
    JOIN members m ON m.id = a.member_id
    WHERE a.service_date = '$date'
    AND a.service_type = '$type'
    ORDER BY a.status DESC, m.full_name ASC
");

$total = numRows("
    SELECT id 
    FROM attendance 
    WHERE service_date = '$date' 
    AND service_type = '$type'
");

$present = numRows("
    SELECT id 
    FROM attendance 
    WHERE service_date = '$date' 
    AND service_type = '$type' 
    AND status = 'Present'
");
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<div class="page-header">
    <h2>
        <i class="fas fa-clipboard-list"></i>
        <?= san($type) ?> &mdash; <?= san($date) ?>
    </h2>

    <a href="index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<div class="row mb-3">

    <div class="col-md-3">
        <div class="stat-card" style="background:var(--primary)">
            <div class="stat-number"><?= $total ?></div>
            <div class="stat-label">Total</div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card" style="background:#28a745">
            <div class="stat-number"><?= $present ?></div>
            <div class="stat-label">Present</div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card" style="background:#dc3545">
            <div class="stat-number"><?= $total - $present ?></div>
            <div class="stat-label">Absent</div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card" style="background:#17a2b8">
            <div class="stat-number">
                <?= $total > 0 ? round(($present / $total) * 100) : 0 ?>%
            </div>
            <div class="stat-label">Attendance Rate</div>
        </div>
    </div>

</div>

<div class="card">
<table class="table">

<thead>
<tr>
    <th>#</th>
    <th>Member</th>
    <th>No.</th>
    <th>Department</th>
    <th>Status</th>
</tr>
</thead>

<tbody>
<?php $i = 1; while ($r = mysqli_fetch_assoc($records)): ?>
<tr>
    <td><?= $i++ ?></td>
    <td><?= san($r['member_name']) ?></td>
    <td><?= san($r['member_no']) ?></td>
    <td><?= san($r['department']) ?></td>
    <td>
        <span class="badge badge-<?= $r['status'] == 'Present' ? 'success' : 'danger' ?>">
            <?= san($r['status']) ?>
        </span>
    </td>
</tr>
<?php endwhile; ?>
</tbody>

</table>
</div>

</div>
</div>

<?php include '../includes/footer.php'; ?>