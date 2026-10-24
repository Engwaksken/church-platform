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
    return ($d && strtotime((string)$d)) ? date('d M Y', strtotime((string)$d)) : '-';
}

$from = $_GET['from'] ?? date('Y-01-01');
$to   = $_GET['to'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-01-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$generated = date('d F Y, H:i');

$stmt = $conn->prepare("
    SELECT 
        t.*,
        m.full_name AS full_name
    FROM thanksgiving t
    LEFT JOIN members m ON m.id = t.member_id
    WHERE t.thanksgiving_date BETWEEN ? AND ?
    ORDER BY t.thanksgiving_date DESC
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$rows = $stmt->get_result();

$totalStmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(amount), 0) AS total,
        COUNT(id) AS total_count
    FROM thanksgiving
    WHERE thanksgiving_date BETWEEN ? AND ?
");
$totalStmt->bind_param("ss", $from, $to);
$totalStmt->execute();
$totalRow = $totalStmt->get_result()->fetch_assoc();
$totalStmt->close();

$total = (float)($totalRow['total'] ?? 0);
$count = (int)($totalRow['total_count'] ?? 0);

$methodStmt = $conn->prepare("
    SELECT 
        payment_method,
        COUNT(*) AS cnt,
        COALESCE(SUM(amount), 0) AS tot
    FROM thanksgiving
    WHERE thanksgiving_date BETWEEN ? AND ?
    GROUP BY payment_method
    ORDER BY tot DESC
");
$methodStmt->bind_param("ss", $from, $to);
$methodStmt->execute();
$by_method = $methodStmt->get_result();

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Thanksgiving Report</title>
<style>
body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#111;padding:20px}
.hdr{border-bottom:3px solid #6610f2;padding-bottom:10px;margin-bottom:15px}
.hdr h1{color:#4a1e8f;font-size:14px;margin:2px 0}
.hdr h2{color:#6610f2;font-size:12px;margin:2px 0}
.meta{float:right;font-size:9px;color:#888;text-align:right}
table{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:9px}
th{background:#4a1e8f;color:#fff;padding:5px 6px;text-align:left}
td{padding:4px 6px;border-bottom:1px solid #eee}
tr:nth-child(even) td{background:#f5f0ff}
.sec td{background:#e8d5ff;font-weight:bold;color:#6610f2;padding:5px 6px}
.tot td{background:#6610f2;color:#fff;font-weight:bold}
.r{text-align:right}
.footer{margin-top:12px;font-size:8px;color:#aaa;text-align:center}
</style>
</head>

<body>

<div class="hdr">
  <div class="meta">
    Period: <?= e(fmtDate($from)) ?> - <?= e(fmtDate($to)) ?><br>
    Generated: <?= e($generated) ?>
  </div>

  <h2>THANKSGIVING OFFERINGS REPORT</h2>
</div>

<table>
<thead>
<tr><th colspan="3">By Payment Method</th></tr>
<tr class="sec">
  <td>Method</td>
  <td class="r">Count</td>
  <td class="r">Total (UGX)</td>
</tr>
</thead>

<tbody>
<?php if ($by_method && $by_method->num_rows > 0): ?>
  <?php while ($r = $by_method->fetch_assoc()): ?>
    <tr>
      <td><?= e($r['payment_method'] ?? '-') ?></td>
      <td class="r"><?= number_format((int)($r['cnt'] ?? 0)) ?></td>
      <td class="r"><?= money($r['tot'] ?? 0) ?></td>
    </tr>
  <?php endwhile; ?>
<?php else: ?>
  <tr>
    <td colspan="3" style="text-align:center">No thanksgiving records found.</td>
  </tr>
<?php endif; ?>
</tbody>

<tfoot>
<tr class="tot">
  <td>TOTAL (<?= number_format($count) ?> offerings)</td>
  <td class="r">-</td>
  <td class="r"><?= money($total) ?></td>
</tr>
</tfoot>
</table>

<table>
<thead>
<tr><th colspan="6">Detailed Records</th></tr>
<tr class="sec">
  <td>Receipt</td>
  <td>Giver</td>
  <td>Reason</td>
  <td>Method</td>
  <td>Date</td>
  <td class="r">Amount (UGX)</td>
</tr>
</thead>

<tbody>
<?php if ($rows && $rows->num_rows > 0): ?>
  <?php while ($r = $rows->fetch_assoc()): ?>
    <?php
      $giver = trim($r['full_name'] ?? '');
      if ($giver === '') {
          $giver = $r['giver_name'] ?? '-';
      }
    ?>
    <tr>
      <td><?= e($r['receipt_no'] ?? '-') ?></td>
      <td><?= e($giver) ?></td>
      <td><?= e($r['reason'] ?? '-') ?></td>
      <td><?= e($r['payment_method'] ?? '-') ?></td>
      <td><?= e(fmtDate($r['thanksgiving_date'] ?? '')) ?></td>
      <td class="r"><?= money($r['amount'] ?? 0) ?></td>
    </tr>
  <?php endwhile; ?>
<?php else: ?>
  <tr>
    <td colspan="6" style="text-align:center">No detailed thanksgiving records found.</td>
  </tr>
<?php endif; ?>
</tbody>

<tfoot>
<tr class="tot">
  <td colspan="5">TOTAL</td>
  <td class="r"><?= money($total) ?></td>
</tr>
</tfoot>
</table>

<div class="footer">
  Thanksgiving Report - <?= e($generated) ?>
</div>

</body>
</html>
<?php
require_once __DIR__ . '/report-branding.php';
$html = report_brand_html(ob_get_clean());

if (isset($_GET['pdf'])) {
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($autoload)) {
        die('Install DomPDF first: composer require dompdf/dompdf');
    }

    require_once $autoload;

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('Thanksgiving_Report_' . date('Ymd') . '.pdf', ['Attachment' => true]);
    exit;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<div class="page-header">
  <h2><i class="fas fa-praying-hands"></i> Thanksgiving Report</h2>

  <div>
    <a href="finance.php" class="btn btn-secondary mr-1">
      <i class="fas fa-arrow-left"></i> Back
    </a>

    <button onclick="window.print()" class="btn btn-primary mr-1">
      <i class="fas fa-print"></i> Print
    </button>

    <a href="?from=<?= e($from) ?>&to=<?= e($to) ?>&pdf=1" class="btn btn-danger">
      <i class="fas fa-file-pdf"></i> PDF
    </a>
  </div>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="GET" class="form-inline">
      <label class="mr-1">From:</label>
      <input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm mr-2">

      <label class="mr-1">To:</label>
      <input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm mr-2">

      <button class="btn btn-sm btn-secondary">
        <i class="fas fa-filter"></i> Apply
      </button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <iframe srcdoc="<?= e($html) ?>" style="width:100%;min-height:600px;border:none"></iframe>
  </div>
</div>

</div>
</div>

<?php
$stmt->close();
$methodStmt->close();
include '../includes/footer.php';
?>
