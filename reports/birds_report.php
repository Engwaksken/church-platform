<?php
require_once '../config/config.php';
requireLogin();

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fmtDate($d): string {
    return ($d && strtotime($d)) ? date('d M Y', strtotime($d)) : '-';
}

/* ======================
   DATA FETCH
====================== */
$allocations = $conn->query("
    SELECT 
        ba.*, 
        b.bird_no, 
        b.breed, 
        b.status AS bird_status,
        m.full_name AS youth_name,
        m.phone AS youth_phone
    FROM bird_allocations ba
    JOIN birds b ON b.id = ba.bird_id
    JOIN youth y ON y.id = ba.youth_id
     JOIN members m ON m.id = y.member_id
    ORDER BY ba.allocated_on DESC
");

$returns = $conn->query("
    SELECT 
        cr.*, 
        b.bird_no,
        m.full_name AS full_name
    FROM chick_returns cr
    JOIN bird_allocations ba ON ba.id = cr.allocation_id
    JOIN birds b ON b.id = ba.bird_id
    JOIN youth y ON y.id = ba.youth_id
    JOIN members m ON m.id = y.member_id
    ORDER BY cr.return_date DESC
");


$stats = [
    'total'    => numRows("SELECT id FROM birds"),
    'active'   => numRows("SELECT id FROM bird_allocations WHERE status='Active'"),
    'complete' => numRows("SELECT id FROM bird_allocations WHERE status='Completed'"),
    'overdue'  => numRows("SELECT id FROM bird_allocations WHERE status='Active' AND return_due_date < CURDATE()"),
];

$generated = date('d M Y H:i');


ob_start();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body{font-family:DejaVu Sans,sans-serif;font-size:10px;margin:20px}
h1,h3,h4{color:#4a1e8f}
table{width:100%;border-collapse:collapse;margin-bottom:12px}
th{background:#4a1e8f;color:#fff;padding:5px}
td{padding:4px;border-bottom:1px solid #eee}
.stats{display:flex}
.stat-box{flex:1;text-align:center;background:#4a1e8f;color:#fff;padding:10px;margin:2px}
.badge-active{background:#28a745;color:#fff;padding:2px 5px}
.badge-overdue{background:#dc3545;color:#fff;padding:2px 5px}
.badge-done{background:#6c757d;color:#fff;padding:2px 5px}
</style>
</head>

<body>
<?php if (!isset($_GET['pdf'])): ?><div class="no-print" style="margin:0 0 16px;display:flex;gap:8px"><a class="btn btn-secondary" href="index.php"><i class="fas fa-arrow-left"></i> Back to Reports</a><a class="btn btn-danger" href="?pdf=1"><i class="fas fa-file-pdf"></i> PDF</a></div><?php endif; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<h3>Birds Empowerment Project Report</h3>
<p>Generated: <?= e($generated) ?></p>

<div class="stats">
<div class="stat-box"><?= $stats['total'] ?><br>Total Birds</div>
<div class="stat-box"><?= $stats['active'] ?><br>Active</div>
<div class="stat-box"><?= $stats['overdue'] ?><br>Overdue</div>
<div class="stat-box"><?= $stats['complete'] ?><br>Completed</div>
</div>

<h4>Allocations</h4>
<table>
<tr>
<th>#</th><th>Bird</th><th>Youth</th>
<th>Allocated</th><th>Due</th><th>Status</th>
</tr>

<?php $i=1; while($a = $allocations->fetch_assoc()):
    $overdue = ($a['status']=='Active' && $a['return_due_date'] < date('Y-m-d'));
    $badge = $a['status']=='Active'
        ? ($overdue ? 'badge-overdue' : 'badge-active')
        : 'badge-done';
?>
<tr>
<td><?= $i++ ?></td>
<td><?= e($a['bird_no']) ?></td>
<td><?= e($a['youth_name']) ?></td>
<td><?= fmtDate($a['allocated_on']) ?></td>
<td><?= fmtDate($a['return_due_date']) ?></td>
<td><span class="<?= $badge ?>"><?= e($a['status']) ?></span></td>
</tr>
<?php endwhile; ?>
</table>

<h4>Returns</h4>
<table>
<tr>
<th>#</th><th>Bird</th><th>Youth</th>
<th>Date</th><th>Chicks</th>
</tr>

<?php $i=1; while($r = $returns->fetch_assoc()): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= e($r['bird_no']) ?></td>
<td><?= e($r['full_name']) ?></td>
<td><?= fmtDate($r['return_date']) ?></td>
<td><?= e($r['chick_count']) ?></td>
</tr>
<?php endwhile; ?>
</table>

</body>
</html>
<?php
require_once __DIR__ . '/report-branding.php';
$html = report_brand_html(ob_get_clean());

/* ======================
   PDF EXPORT
====================== */
if (isset($_GET['pdf'])) {

    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($autoload)) {
        die('Run: composer require dompdf/dompdf');
    }

    require_once $autoload;

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $dompdf->stream('Birds_Report_' . date('Ymd') . '.pdf');
    exit;
}

/* ======================
   OUTPUT
====================== */
echo $html;
