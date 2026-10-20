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

$generated = date('d F Y, H:i');

/* ======================
   FETCH DATA (SAFE)
====================== */
$stmt = $conn->prepare("
    SELECT *
    FROM offertory
    WHERE service_date BETWEEN ? AND ?
    ORDER BY service_date DESC
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$rows = $stmt->get_result();

/* ======================
   TOTALS
====================== */
function getTotal($conn, $col, $from, $to) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM($col),0) AS total
        FROM offertory
        WHERE service_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return (float)$res['total'];
}

$total = getTotal($conn, 'total_amount', $from, $to);
$env   = getTotal($conn, 'envelope_total', $from, $to);
$loose = getTotal($conn, 'loose_total', $from, $to);

$countStmt = $conn->prepare("
    SELECT COUNT(id) AS cnt
    FROM offertory
    WHERE service_date BETWEEN ? AND ?
");
$countStmt->bind_param("ss", $from, $to);
$countStmt->execute();
$count = (int)$countStmt->get_result()->fetch_assoc()['cnt'];

/* ======================
   BY SERVICE TYPE
====================== */
$typeStmt = $conn->prepare("
    SELECT service_type, COUNT(*) cnt, SUM(total_amount) tot
    FROM offertory
    WHERE service_date BETWEEN ? AND ?
    GROUP BY service_type
    ORDER BY tot DESC
");
$typeStmt->bind_param("ss", $from, $to);
$typeStmt->execute();
$by_type = $typeStmt->get_result();

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
body{font-family:DejaVu Sans,Arial;font-size:10px;padding:20px}
table{width:100%;border-collapse:collapse;margin-bottom:12px}
th{background:#4a1e8f;color:#fff;padding:5px}
td{padding:4px;border-bottom:1px solid #eee}
.r{text-align:right}
.tot td{background:#28a745;color:#fff;font-weight:bold}
.sec td{background:#d4edda;font-weight:bold}
</style>
</head>

<body>

<h3>Offertory Report</h3>
<p><?= fmtDate($from) ?> - <?= fmtDate($to) ?> | <?= e($generated) ?></p>

<table>
<tr class="sec">
<td>Service Type</td>
<td class="r">Count</td>
<td class="r">Total</td>
</tr>

<?php while($r = $by_type->fetch_assoc()): ?>
<tr>
<td><?= e($r['service_type']) ?></td>
<td class="r"><?= $r['cnt'] ?></td>
<td class="r"><?= money($r['tot']) ?></td>
</tr>
<?php endwhile; ?>

<tr class="tot">
<td>TOTAL (<?= $count ?> services)</td>
<td></td>
<td><?= money($total) ?></td>
</tr>
</table>

<table>
<tr><th>Type</th><th class="r">Amount</th><th class="r">%</th></tr>

<tr>
<td>Envelope</td>
<td class="r"><?= money($env) ?></td>
<td class="r"><?= $total>0?round($env/$total*100,1):0 ?>%</td>
</tr>

<tr>
<td>Loose</td>
<td class="r"><?= money($loose) ?></td>
<td class="r"><?= $total>0?round($loose/$total*100,1):0 ?>%</td>
</tr>

<tr class="tot">
<td>Total</td>
<td class="r"><?= money($total) ?></td>
<td>100%</td>
</tr>
</table>

<table>
<tr>
<th>Date</th><th>Service</th>
<th class="r">Envelope</th>
<th class="r">Loose</th>
<th class="r">Total</th>
</tr>

<?php while($r = $rows->fetch_assoc()): ?>
<tr>
<td><?= fmtDate($r['service_date']) ?></td>
<td><?= e($r['service_type']) ?></td>
<td class="r"><?= money($r['envelope_total']) ?></td>
<td class="r"><?= money($r['loose_total']) ?></td>
<td class="r"><strong><?= money($r['total_amount']) ?></strong></td>
</tr>
<?php endwhile; ?>

<tr class="tot">
<td colspan="4">TOTAL</td>
<td><?= money($total) ?></td>
</tr>
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

    $dompdf->stream('Offertory_Report_' . date('Ymd') . '.pdf');
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

<h2>Offertory Report</h2>

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
