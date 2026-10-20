<?php
require_once '../config/config.php';
requireLogin();

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v): string {
    return number_format((float)$v);
}

function fmtDate($date): string {
    if (empty($date) || strtotime($date) === false) return '-';
    return date('d M Y', strtotime($date));
}

$from = $_GET['from'] ?? date('Y-01-01');
$to   = $_GET['to'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = date('Y-m-d');

$generated = date('d F Y, H:i');

/* ========================
   FETCH PLEDGES
======================== */
$stmt = $conn->prepare("
    SELECT 
        p.*,
        CONCAT_WS(' ', m.first_name, m.last_name) AS full_name,
        m.member_no,
        pr.name AS project_name
    FROM pledges p
    LEFT JOIN members m ON m.id = p.member_id
    LEFT JOIN projects pr ON pr.id = p.project_id
    WHERE p.pledge_date BETWEEN ? AND ?
    ORDER BY p.status ASC, p.due_date ASC
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$rows = $stmt->get_result();

/* ========================
   SUMMARY STATS
======================== */
$statsStmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(amount_pledged),0) AS total_pledged,
        COALESCE(SUM(amount_paid),0) AS total_paid
    FROM pledges
    WHERE pledge_date BETWEEN ? AND ?
");
$statsStmt->bind_param("ss", $from, $to);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$counts = [
    'pending'   => numRows("SELECT id FROM pledges WHERE status='Pending'"),
    'partial'   => numRows("SELECT id FROM pledges WHERE status='Partial'"),
    'fulfilled' => numRows("SELECT id FROM pledges WHERE status='Fulfilled'"),
    'overdue'   => numRows("SELECT id FROM pledges WHERE status IN ('Pending','Partial') AND due_date < CURDATE()")
];

$outstanding = $stats['total_pledged'] - $stats['total_paid'];

/* ========================
   HTML REPORT
======================== */
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;padding:20px}
.hdr{border-bottom:3px solid #20c997;margin-bottom:15px}
.hdr h2{color:#20c997}
.meta{float:right;font-size:9px}
table{width:100%;border-collapse:collapse;margin-bottom:12px}
th{background:#4a1e8f;color:#fff;padding:5px}
td{padding:4px;border-bottom:1px solid #eee}
.r{text-align:right}
.sec td{background:#d0f5ed;font-weight:bold}
.tot td{background:#20c997;color:#fff;font-weight:bold}
.pending td{background:#fff9c4}
.overdue td{background:#ffd7d7}
.fulfilled td{background:#d4edda}
</style>
</head>
<body>

<div class="hdr">
  <div class="meta">
    <?= fmtDate($from) ?> - <?= fmtDate($to) ?><br>
    <?= e($generated) ?>
  </div>
  <h2>PLEDGES REPORT</h2>
</div>

<table>
<tr><td>Total Pledged</td><td class="r"><?= money($stats['total_pledged']) ?></td></tr>
<tr><td>Paid</td><td class="r"><?= money($stats['total_paid']) ?></td></tr>
<tr><td><strong>Outstanding</strong></td><td class="r"><?= money($outstanding) ?></td></tr>

<tr class="sec">
<td>Pending: <?= $counts['pending'] ?></td>
<td>Partial: <?= $counts['partial'] ?></td>
</tr>

<tr class="sec">
<td>Fulfilled: <?= $counts['fulfilled'] ?></td>
<td>Overdue: <?= $counts['overdue'] ?></td>
</tr>
</table>

<table>
<thead>
<tr>
<th>No</th><th>Member</th><th>Project</th>
<th class="r">Pledged</th>
<th class="r">Paid</th>
<th class="r">Balance</th>
<th>Status</th>
</tr>
</thead>
<tbody>

<?php while($r = $rows->fetch_assoc()):
    $bal = $r['amount_pledged'] - $r['amount_paid'];
    $overdue = ($r['status'] != 'Fulfilled' && $r['due_date'] && $r['due_date'] < date('Y-m-d'));

    $cls = $r['status'] == 'Fulfilled' ? 'fulfilled' : ($overdue ? 'overdue' : 'pending');
?>
<tr class="<?= $cls ?>">
<td><?= e($r['pledge_no']) ?></td>
<td><?= e($r['full_name'] ?? '-') ?></td>
<td><?= e($r['project_name'] ?? 'General') ?></td>
<td class="r"><?= money($r['amount_pledged']) ?></td>
<td class="r"><?= money($r['amount_paid']) ?></td>
<td class="r"><?= money($bal) ?></td>
<td>
    <?= e($r['status']) ?>
    <?= $overdue ? '️ OVERDUE' : '' ?>
</td>
</tr>
<?php endwhile; ?>

</tbody>

<tfoot>
<tr class="tot">
<td colspan="3">TOTAL</td>
<td class="r"><?= money($stats['total_pledged']) ?></td>
<td class="r"><?= money($stats['total_paid']) ?></td>
<td class="r"><?= money($outstanding) ?></td>
<td></td>
</tr>
</tfoot>
</table>

</body>
</html>
<?php
require_once __DIR__ . '/report-branding.php';
$html = report_brand_html(ob_get_clean());

/* ========================
   PDF EXPORT
======================== */
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
    $dompdf->render();

    $dompdf->stream("Pledges_Report_" . date('Ymd') . ".pdf");
    exit;
}

/* ========================
   UI PREVIEW
======================== */
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<div class="page-header">
<h2>Pledges Report</h2>

<div>
<a href="index.php" class="btn btn-secondary mr-1"><i class="fas fa-arrow-left"></i> Back to Reports</a>
<a href="?from=<?= e($from) ?>&to=<?= e($to) ?>&pdf=1" class="btn btn-danger">
PDF
</a>
</div>
</div>

<div class="card">
<div class="card-body p-0">
<iframe srcdoc="<?= e($html) ?>" style="width:100%;height:700px;border:none"></iframe>
</div>
</div>

</div>
</div>

<?php include '../includes/footer.php'; ?>
