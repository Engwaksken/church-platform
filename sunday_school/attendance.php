<?php
require_once '../config/config.php';
requireLogin();

$class_id = intval($_GET['class_id'] ?? 0);

$classes = mysqli_query($conn, "
    SELECT id, class_name
    FROM sunday_school_classes
    ORDER BY class_name ASC
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $date    = san($_POST['lesson_date']);
    $cid     = intval($_POST['class_id_post']);
    $present = $_POST['present'] ?? [];

    $pupils = mysqli_query($conn, "
        SELECT id
        FROM sunday_school_pupils
        WHERE class_id = $cid
        AND status = 'Active'
    ");

    mysqli_query($conn, "
        DELETE FROM sunday_school_attendance
        WHERE lesson_date = '$date'
        AND class_id = $cid
    ");

    while ($p = mysqli_fetch_assoc($pupils)) {
        $pid = intval($p['id']);

        $status = in_array((string)$pid, array_map('strval', $present), true)
            ? 'Present'
            : 'Absent';

        mysqli_query($conn, "
            INSERT INTO sunday_school_attendance (
                pupil_id,
                class_id,
                lesson_date,
                status
            ) VALUES (
                $pid,
                $cid,
                '$date',
                '$status'
            )
        ");
    }

    setFlash('Sunday School attendance saved successfully.', 'success');
    redirect('index.php');
}

$pupils = null;

if ($class_id) {
    $pupils = mysqli_query($conn, "
        SELECT *
        FROM sunday_school_pupils
        WHERE class_id = $class_id
        AND status = 'Active'
        ORDER BY full_name ASC
    ");
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<?= showFlash(); ?>

<div class="page-header">
    <h2><i class="fas fa-clipboard-check"></i> Sunday School Attendance</h2>
    <a href="index.php" class="btn btn-secondary">Back</a>
</div>

<div class="card mb-3">
<div class="card-body">

<form method="GET" class="form-inline">
    <label class="mr-2">Select Class:</label>

    <select name="class_id" class="form-control mr-2">
        <option value="">-- Choose Class --</option>

        <?php while ($c = mysqli_fetch_assoc($classes)): ?>
            <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
                <?= san($c['class_name']) ?>
            </option>
        <?php endwhile; ?>
    </select>

    <button class="btn btn-primary">Load Pupils</button>
</form>

</div>
</div>

<?php if ($pupils && mysqli_num_rows($pupils) > 0): ?>

<div class="card">
<div class="card-body">

<form method="POST">

<input type="hidden" name="class_id_post" value="<?= $class_id ?>">

<div class="form-row mb-3">
    <div class="form-group col-md-4">
        <label>Lesson Date *</label>
        <input type="date"
               name="lesson_date"
               class="form-control"
               value="<?= date('Y-m-d') ?>"
               required>
    </div>
</div>

<div class="d-flex justify-content-between mb-2">
    <h5>Pupils</h5>

    <div>
        <button type="button" class="btn btn-sm btn-success" onclick="checkAll(true)">
            All Present
        </button>

        <button type="button" class="btn btn-sm btn-secondary" onclick="checkAll(false)">
            Clear
        </button>
    </div>
</div>

<div class="row" id="pList">

<?php while ($p = mysqli_fetch_assoc($pupils)): ?>

    <div class="col-md-4 mb-2">
        <div class="d-flex align-items-center p-2 border rounded">
            <input type="checkbox"
                   name="present[]"
                   value="<?= $p['id'] ?>"
                   id="p<?= $p['id'] ?>"
                   class="mr-2"
                   checked>

            <label for="p<?= $p['id'] ?>" class="mb-0">
                <strong><?= san($p['full_name']) ?></strong><br>
                <small class="text-muted"><?= san($p['pupil_no']) ?></small>
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

<?php elseif ($class_id): ?>

<div class="alert alert-warning">
    No active pupils found in this class.
</div>

<?php endif; ?>

</div>
</div>

<script>
function checkAll(status) {
    document.querySelectorAll('#pList input[type="checkbox"]').forEach(function (checkbox) {
        checkbox.checked = status;
    });
}
</script>

<?php include '../includes/footer.php'; ?>