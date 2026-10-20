<?php
require_once '../config/config.php';
requireLogin();

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v): string {
    return number_format((float)$v);
}

function fmtDate($d): string {
    return ($d && strtotime($d)) ? date('d M Y', strtotime($d)) : '-';
}

$from = $_GET['from'] ?? date('Y-01-01');
$to   = $_GET['to'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = date('Y-m-d');

$generated = date('d M Y H:i');

/* ======================
   CONTRIBUTIONS LIST
====================== */
$stmt = $conn->prepare("
    SELECT 
        c.*,
        CONCAT_WS(' ', m.first_name, m.last_name) AS full_name,
        m.member_no,
        p.name AS project_name
    FROM contributions c
    JOIN members m ON m.id = c.member_id
    LEFT JOIN projects p ON p.id = c.project_id
    WHERE c.contributed_on BETWEEN ? AND ?
    ORDER BY p.name, c.contributed_on DESC
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$contributions = $stmt->get_result();

/* ======================
   PROJECT TOTALS
====================== */
$stmt2 = $conn->prepare("
    SELECT 
        p.name,
        COUNT(c.id) AS cnt,
        COALESCE(SUM(c.amount),0) AS total
    FROM projects p
    LEFT JOIN contributions c 
        ON c.project_id = p.id
        AND c.contributed_on BETWEEN ? AND ?
    GROUP BY p.id
    ORDER BY total DESC
");
$stmt2->bind_param("ss", $from, $to);
$stmt2->execute();
$project_totals = $stmt2->get_result();

/* ======================
   GRAND TOTAL
====================== */
$stmt3 = $conn->prepare("
    SELECT COALESCE(SUM(amount),0) AS total
    FROM contributions
    WHERE contributed_on BETWEEN ? AND ?
");
$stmt3->bind_param("ss", $from, $to);
$stmt3->execute();
$grand_total = (float)($stmt3->get_result()->fetch_assoc()['total'] ?? 0);
$stmt3->close();

/* ======================
   HTML REPORT
====================== */
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body{font-family:DejaVu Sans,sans-serif;font-size:10px;margin:20px}
table{width:100%;border-collapse:collapse;margin-bottom:12px}
th{background:#4a1e8f;color:#fff;padding:5px}
td{padding:4px;border-bottom:1px solid #eee}
.r{text-align:right}
.total-row td{background:#d4a017;color:#fff;font-weight:bold}
.header{margin-bottom:10px}
</style>
</head>

<body>

<div class="header">
<h3>Contributions Report</h3>
<p><?= fmtDate($from) ?> - <?= fmtDate($to) ?> | <?= e($generated) ?></p>
</div>

<h4>Total: UGX <?= money($grand_total) ?></h4>

<table>
<tr>
<th>#</th><th>Project</th><th>Count</th><th>Total</th>
</tr>

<?php $i=1; while($pr = $project_totals->fetch_assoc()): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= e($pr['name']) ?></td>
<td><?= $pr['cnt'] ?></td>
<td><?= money($pr['total']) ?></td>
</tr>
<?php endwhile; ?>

<tr class="total-row">
<td colspan="3">TOTAL</td>
<td><?= money($grand_total) ?></td>
</tr>
</table>

<table>
<tr>
<th>#</th><th>Receipt</th><th>Member</th>
<th>Project</th><th>Amount</th><th>Date</th>
</tr>

<?php $i=1; while($c = $contributions->fetch_assoc()): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= e($c['receipt_no']) ?></td>
<td><?= e($c['full_name']) ?></td>
<td><?= e($c['project_name'] ?? '-') ?></td>
<td><?= money($c['amount']) ?></td>
<td><?= fmtDate($c['contributed_on']) ?></td>
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
    $dompdf->render();

    $dompdf->stream('Contributions_Report_' . date('Ymd') . '.pdf');
    exit;
}

/* ======================
   UI
====================== */
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<h2>Contributions Report</h2>

<a href="?from=<?= e($from) ?>&to=<?= e($to) ?>&pdf=1" class="btn btn-danger">
PDF
</a>

<div class="card">
<div class="card-body p-0">
<iframe srcdoc="<?= e($html) ?>" style="width:100%;height:700px;border:none"></iframe>
</div>
</div>

</div>
</div>

<?php include '../includes/footer.php'; ?>
