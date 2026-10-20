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
   CAMPAIGNS SUMMARY
====================== */
$stmt = $conn->prepare("
    SELECT 
        f.*,
        COALESCE(SUM(fc.amount),0) AS raised,
        COUNT(fc.id) AS donations
    FROM fundraising f
    LEFT JOIN fundraising_collections fc 
        ON fc.campaign_id = f.id
        AND fc.collection_date BETWEEN ? AND ?
    GROUP BY f.id
    ORDER BY raised DESC
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$campaigns = $stmt->get_result();

/* ======================
   GRAND TOTAL
====================== */
$sumStmt = $conn->prepare("
    SELECT COALESCE(SUM(amount),0) AS total
    FROM fundraising_collections
    WHERE collection_date BETWEEN ? AND ?
");
$sumStmt->bind_param("ss", $from, $to);
$sumStmt->execute();
$grand_raised = (float)($sumStmt->get_result()->fetch_assoc()['total'] ?? 0);
$sumStmt->close();

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
.hdr{border-bottom:3px solid #d4a017;margin-bottom:12px}
.meta{float:right;font-size:9px}
table{width:100%;border-collapse:collapse;margin-bottom:12px}
th{background:#4a1e8f;color:#fff;padding:5px}
td{padding:4px;border-bottom:1px solid #eee}
.r{text-align:right}
.sec td{background:#fff3cc;font-weight:bold}
.tot td{background:#d4a017;color:#fff;font-weight:bold}
</style>
</head>
<body>

<div class="hdr">
  <div class="meta">
    <?= fmtDate($from) ?> - <?= fmtDate($to) ?><br>
    <?= e($generated) ?>
  </div>
  <h2>FUNDRAISING REPORT</h2>
</div>

<table>
<thead>
<tr class="sec">
<td>Campaign</td>
<td>Status</td>
<td class="r">Target</td>
<td class="r">Raised</td>
<td class="r">%</td>
<td class="r">Donations</td>
</tr>
</thead>
<tbody>

<?php while($c = $campaigns->fetch_assoc()):
    $pct = ($c['target_amount'] > 0) 
        ? min(100, round(($c['raised'] / $c['target_amount']) * 100)) 
        : 0;
?>
<tr>
<td><?= e($c['title']) ?></td>
<td><?= e($c['status']) ?></td>
<td class="r"><?= money($c['target_amount']) ?></td>
<td class="r"><?= money($c['raised']) ?></td>
<td class="r"><?= $pct ?>%</td>
<td class="r"><?= $c['donations'] ?></td>
</tr>
<?php endwhile; ?>

</tbody>

<tfoot>
<tr class="tot">
<td colspan="3">TOTAL RAISED</td>
<td class="r"><?= money($grand_raised) ?></td>
<td colspan="2"></td>
</tr>
</tfoot>
</table>

<?php
/* ======================
   DETAILS PER CAMPAIGN
====================== */
$campList = mysqli_query($conn, "SELECT id, title FROM fundraising");

while ($camp = mysqli_fetch_assoc($campList)) {

    $stmt2 = $conn->prepare("
        SELECT 
            fc.*,
            CONCAT_WS(' ', m.first_name, m.last_name) AS full_name
        FROM fundraising_collections fc
        LEFT JOIN members m ON m.id = fc.member_id
        WHERE fc.campaign_id = ?
        AND fc.collection_date BETWEEN ? AND ?
        ORDER BY fc.collection_date DESC
    ");
    $stmt2->bind_param("iss", $camp['id'], $from, $to);
    $stmt2->execute();
    $cols = $stmt2->get_result();

    $sum2 = $conn->prepare("
        SELECT COALESCE(SUM(amount),0) AS total
        FROM fundraising_collections
        WHERE campaign_id = ?
        AND collection_date BETWEEN ? AND ?
    ");
    $sum2->bind_param("iss", $camp['id'], $from, $to);
    $sum2->execute();
    $ctotal = (float)($sum2->get_result()->fetch_assoc()['total'] ?? 0);
    $sum2->close();

    if ($ctotal <= 0) continue;
?>

<table>
<thead>
<tr class="sec">
<td colspan="5"><?= e($camp['title']) ?></td>
</tr>
<tr class="sec">
<td>Donor</td>
<td>Method</td>
<td>Item</td>
<td>Date</td>
<td class="r">Amount</td>
</tr>
</thead>

<tbody>
<?php while ($col = $cols->fetch_assoc()): ?>
<tr>
<td><?= e($col['full_name'] ?? $col['donor_name'] ?? 'Anonymous') ?></td>
<td><?= e($col['payment_method']) ?></td>
<td><?= e($col['item_description']) ?></td>
<td><?= fmtDate($col['collection_date']) ?></td>
<td class="r"><?= money($col['amount']) ?></td>
</tr>
<?php endwhile; ?>
</tbody>

<tfoot>
<tr class="tot">
<td colspan="4">Sub Total</td>
<td class="r"><?= money($ctotal) ?></td>
</tr>
</tfoot>
</table>

<?php } ?>

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

    $dompdf->stream('Fundraising_Report_' . date('Ymd') . '.pdf');
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

<div class="page-header">
<h2>Fundraising Report</h2>

<a href="?from=<?= e($from) ?>&to=<?= e($to) ?>&pdf=1" class="btn btn-danger">
PDF
</a>
</div>

<div class="card">
<div class="card-body p-0">
<iframe srcdoc="<?= e($html) ?>" style="width:100%;height:700px;border:none"></iframe>
</div>
</div>

</div>
</div>

<?php include '../includes/footer.php'; ?>
