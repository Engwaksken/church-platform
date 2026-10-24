<?php
require_once '../config/config.php';
requireLogin();

$members = mysqli_query($conn, "
    SELECT 
        id,
        full_name,
        member_no,
        department
    FROM members
    WHERE status = 'Active'
    ORDER BY full_name ASC
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $date  = san($_POST['service_date']);
    $type  = san($_POST['service_type']);
    $notes = san($_POST['notes']);

    $present = $_POST['present'] ?? [];
    $present = array_map('intval', $present);

    mysqli_query($conn, "
        DELETE FROM attendance
        WHERE service_date = '$date'
        AND service_type = '$type'
    ");

    $all_members = mysqli_query($conn, "
        SELECT id
        FROM members
        WHERE status = 'Active'
    ");

    while ($m = mysqli_fetch_assoc($all_members)) {
        $mid = intval($m['id']);

        $status = in_array($mid, $present, true) ? 'Present' : 'Absent';

        mysqli_query($conn, "
            INSERT INTO attendance (
                member_id,
                service_date,
                service_type,
                status,
                notes
            ) VALUES (
                $mid,
                '$date',
                '$type',
                '$status',
                '$notes'
            )
        ");
    }

    setFlash('Attendance saved for ' . $date . ' (' . $type . ').', 'success');
    redirect('index.php');
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<?= showFlash(); ?>

<div class="page-header">
    <h2><i class="fas fa-clipboard-check"></i> Mark Attendance</h2>

    <a href="index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<div class="card">
<div class="card-body">

<form method="POST">

<div class="form-row mb-3">

    <div class="form-group col-md-4">
        <label>Service Date *</label>
        <input type="date"
               name="service_date"
               class="form-control"
               value="<?= date('Y-m-d') ?>"
               required>
    </div>

    <div class="form-group col-md-4">
        <label>Service Type *</label>
        <select name="service_type" class="form-control" required>
            <option>Sunday Service</option>
            <option>Wednesday Bible Study</option>
            <option>Friday Prayer</option>
            <option>Special Service</option>
            <option>Youth Meeting</option>
            <option>Other</option>
        </select>
    </div>

    <div class="form-group col-md-4">
        <label>Notes</label>
        <input type="text"
               name="notes"
               class="form-control"
               placeholder="e.g. Guest preacher">
    </div>

</div>

<div class="d-flex justify-content-between mb-2">
    <h5>Members List</h5>

    <div>
        <button type="button" class="btn btn-sm btn-success" onclick="checkAll(true)">
            Mark All Present
        </button>

        <button type="button" class="btn btn-sm btn-secondary" onclick="checkAll(false)">
            Clear All
        </button>
    </div>
</div>

<div class="row" id="membersList">

<?php while ($m = mysqli_fetch_assoc($members)): ?>
    <?php $memberName = trim((string)$m['full_name']); ?>

    <div class="col-md-4 mb-2">
        <div class="attendance-card d-flex align-items-center p-2 border rounded">

            <input type="checkbox"
                   name="present[]"
                   value="<?= $m['id'] ?>"
                   id="m<?= $m['id'] ?>"
                   class="mr-2"
                   checked>

            <label for="m<?= $m['id'] ?>" class="mb-0">
                <strong><?= san($memberName) ?></strong><br>
                <small class="text-muted">
                    <?= san($m['member_no']) ?> &bull; <?= san($m['department']) ?>
                </small>
            </label>

        </div>
    </div>

<?php endwhile; ?>

</div>

<div class="mt-3">
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save"></i> Save Attendance
    </button>
</div>

</form>

</div>
</div>

</div>
</div>

<script>
function checkAll(state) {
    document.querySelectorAll('#membersList input[type="checkbox"]').forEach(function (cb) {
        cb.checked = state;
    });
}
</script>

<?php include '../includes/footer.php'; ?>