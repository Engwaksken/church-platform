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
    if (empty($date) || strtotime((string)$date) === false) {
        return '-';
    }
    return date('d M Y', strtotime((string)$date));
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
        m.full_name AS full_name,
        m.member_no
    FROM tithe t
    LEFT JOIN members m ON m.id = t.member_id
    WHERE t.tithe_date BETWEEN ? AND ?
    ORDER BY full_name ASC, t.tithe_date ASC
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$rows = $stmt->get_result();

$totalStmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total, COUNT(id) AS total_count
    FROM tithe
    WHERE tithe_date BETWEEN ? AND ?
");
$totalStmt->bind_param("ss", $from, $to);
$totalStmt->execute();
$totalRow = $totalStmt->get_result()->fetch_assoc();
$totalStmt->close();

$total = (float)($totalRow['total'] ?? 0);
$count = (int)($totalRow['total_count'] ?? 0);

$memberStmt = $conn->prepare("
    SELECT 
        m.full_name AS full_name,
        m.member_no,
        COUNT(t.id) AS payment_count,
        COALESCE(SUM(t.amount), 0) AS total
    FROM tithe t
    LEFT JOIN members m ON m.id = t.member_id
    WHERE t.tithe_date BETWEEN ? AND ?
    GROUP BY t.member_id, m.full_name, m.member_no
    ORDER BY total DESC
");
$memberStmt->bind_param("ss", $from, $to);
$memberStmt->execute();
$by_member = $memberStmt->get_result();

$months_list = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

$reportYear = (int)date('Y', strtotime($from));
$by_month = [];

for ($m = 1; $m <= 12; $m++) {
    $mf = sprintf('%04d-%02d-01', $reportYear, $m);
    $mt = date('Y-m-t', strtotime($mf));

    $monthStmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM tithe
        WHERE tithe_date BETWEEN ? AND ?
    ");
    $monthStmt->bind_param("ss", $mf, $mt);
    $monthStmt->execute();
    $monthRow = $monthStmt->get_result()->fetch_assoc();
    $monthStmt->close();

    $by_month[$m] = (float)($monthRow['total'] ?? 0);
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Tithe Report</title>
<style>
  body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#111;margin:0;padding:20px}
  .hdr{border-bottom:3px solid #fd7e14;padding-bottom:10px;margin-bottom:15px}
  .hdr h1{color:#4a1e8f;font-size:14px;margin:2px 0}
  .hdr h2{color:#fd7e14;font-size:12px;margin:2px 0}
  .meta{float:right;text-align:right;font-size:9px;color:#888}
  table{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:9px}
  th{background:#4a1e8f;color:#fff;padding:5px 6px;text-align:left}
  td{padding:4px 6px;border-bottom:1px solid #eee}
  tr:nth-child(even) td{background:#fff8ee}
  .sec td{background:#ffecd6;font-weight:bold;color:#fd7e14;padding:5px 6px}
  .tot td{background:#fd7e14;color:#fff;font-weight:bold}
  .r{text-align:right}
  .footer{margin-top:12px;font-size:8px;color:#aaa;text-align:center;border-top:1px solid #eee;padding-top:6px}
</style>
</head>

<body>

<div class="hdr">
  <div class="meta">
    Period: <?= e(fmtDate($from)) ?> - <?= e(fmtDate($to)) ?><br>
    Generated: <?= e($generated) ?>
  </div>

  <h2>TITHE REPORT</h2>
</div>

<table>
  <thead>
    <tr><th colspan="4">Summary by Member</th></tr>
    <tr class="sec">
      <td>Member</td>
      <td>No.</td>
      <td class="r">Payments</td>
      <td class="r">Total (UGX)</td>
    </tr>
  </thead>

  <tbody>
    <?php if ($by_member && $by_member->num_rows > 0): ?>
      <?php while ($r = $by_member->fetch_assoc()): ?>
        <tr>
          <td><?= e(trim($r['full_name'] ?? '') ?: 'Anonymous') ?></td>
          <td><?= e($r['member_no'] ?? '-') ?></td>
          <td class="r"><?= number_format((int)($r['payment_count'] ?? 0)) ?></td>
          <td class="r"><?= money($r['total'] ?? 0) ?></td>
        </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr>
        <td colspan="4" style="text-align:center">No tithe records found.</td>
      </tr>
    <?php endif; ?>
  </tbody>

  <tfoot>
    <tr class="tot">
      <td colspan="2">TOTAL</td>
      <td class="r"><?= number_format($count) ?></td>
      <td class="r"><?= money($total) ?></td>
    </tr>
  </tfoot>
</table>

<table>
  <thead>
    <tr><th colspan="3">Monthly Tithe Totals (<?= e($reportYear) ?>)</th></tr>
    <tr class="sec">
      <td>Month</td>
      <td class="r">Amount (UGX)</td>
      <td class="r">Cumulative</td>
    </tr>
  </thead>

  <tbody>
    <?php $cum = 0; ?>
    <?php foreach ($by_month as $m => $amt): ?>
      <?php $cum += $amt; ?>
      <tr>
        <td><?= e($months_list[$m]) ?></td>
        <td class="r"><?= money($amt) ?></td>
        <td class="r"><?= money($cum) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<table>
  <thead>
    <tr><th colspan="5">Detailed Tithe Records</th></tr>
    <tr class="sec">
      <td>Receipt No</td>
      <td>Member</td>
      <td>Period</td>
      <td>Method</td>
      <td class="r">Amount (UGX)</td>
    </tr>
  </thead>

  <tbody>
    <?php if ($rows && $rows->num_rows > 0): ?>
      <?php while ($r = $rows->fetch_assoc()): ?>
        <?php
          $periodMonth = (int)($r['period_month'] ?? 0);
          $periodMonth = ($periodMonth >= 1 && $periodMonth <= 12) ? $periodMonth : 1;
          $periodYear = $r['period_year'] ?? '';
        ?>
        <tr>
          <td><?= e($r['receipt_no'] ?? '-') ?></td>
          <td><?= e(trim($r['full_name'] ?? '') ?: '-') ?></td>
          <td><?= e($months_list[$periodMonth] . ' ' . $periodYear) ?></td>
          <td><?= e($r['payment_method'] ?? '-') ?></td>
          <td class="r"><?= money($r['amount'] ?? 0) ?></td>
        </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr>
        <td colspan="5" style="text-align:center">No detailed tithe records found.</td>
      </tr>
    <?php endif; ?>
  </tbody>

  <tfoot>
    <tr class="tot">
      <td colspan="4">TOTAL</td>
      <td class="r"><?= money($total) ?></td>
    </tr>
  </tfoot>
</table>

<div class="footer">
  Tithe Report - <?= e($generated) ?>
</div>

</body>
</html>
<?php
require_once __DIR__ . '/report-branding.php';
$html = report_brand_html(ob_get_clean());

if (isset($_GET['pdf'])) {
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($autoload)) {
        die('Run composer require dompdf/dompdf. <a href="finance.php">Back</a>');
    }

    require_once $autoload;

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('Tithe_Report_' . date('Ymd') . '.pdf', ['Attachment' => true]);
    exit;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<div class="page-header">
  <h2><i class="fas fa-coins"></i> Tithe Report</h2>

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
    <iframe srcdoc="<?= e($html) ?>" style="width:100%;min-height:700px;border:none"></iframe>
  </div>
</div>

</div>
</div>

<?php
$stmt->close();
$memberStmt->close();
include '../includes/footer.php';
?>
