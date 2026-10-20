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
    return date('d F Y', strtotime((string)$date));
}

function sumToDate(mysqli $conn, string $table, string $amountCol, string $dateCol, string $to): float {
    $safe = '/^[a-zA-Z0-9_]+$/';
    if (!preg_match($safe, $table) || !preg_match($safe, $amountCol) || !preg_match($safe, $dateCol)) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT COALESCE(SUM($amountCol), 0) AS v FROM $table WHERE $dateCol <= ?");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("s", $to);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float)($row['v'] ?? 0);
}

function singleValue(mysqli $conn, string $sql): float {
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }

    $row = $res->fetch_assoc();
    return (float)($row['v'] ?? 0);
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

$all_offertory      = sumToDate($conn, 'offertory', 'total_amount', 'service_date', $to);
$all_tithe          = sumToDate($conn, 'tithe', 'amount', 'tithe_date', $to);
$all_thanksgiving   = sumToDate($conn, 'thanksgiving', 'amount', 'thanksgiving_date', $to);
$all_contributions  = sumToDate($conn, 'contributions', 'amount', 'contributed_on', $to);
$all_pledges_paid   = sumToDate($conn, 'pledge_payments', 'amount', 'paid_on', $to);
$all_fundraising    = sumToDate($conn, 'fundraising_collections', 'amount', 'collection_date', $to);

$total_income_alltime =
    $all_offertory +
    $all_tithe +
    $all_thanksgiving +
    $all_contributions +
    $all_pledges_paid +
    $all_fundraising;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount_pledged - amount_paid), 0) AS v
    FROM pledges
    WHERE status IN ('Pending', 'Partial')
      AND pledge_date <= ?
");
$stmt->bind_param("s", $to);
$stmt->execute();
$pledges_outstanding = (float)($stmt->get_result()->fetch_assoc()['v'] ?? 0);
$stmt->close();

$project_budgets = singleValue($conn, "
    SELECT COALESCE(SUM(target_amount), 0) AS v
    FROM projects
    WHERE status = 'Active'
");

$total_commitments = $project_budgets + $pledges_outstanding;
$net_balance = $total_income_alltime - $total_commitments;

$currentYear = (int)date('Y', strtotime($to));
$lastYear = $currentYear - 1;

$sources = [
    'Offertory'     => ['offertory', 'total_amount', 'service_date'],
    'Tithe'         => ['tithe', 'amount', 'tithe_date'],
    'Thanksgiving'  => ['thanksgiving', 'amount', 'thanksgiving_date'],
    'Contributions' => ['contributions', 'amount', 'contributed_on'],
    'Fundraising'   => ['fundraising_collections', 'amount', 'collection_date'],
];

$yearRows = [];
$gtCur = 0;
$gtPrev = 0;

foreach ($sources as $name => $s) {
    [$table, $amountCol, $dateCol] = $s;

    $cur = singleValue($conn, "
        SELECT COALESCE(SUM($amountCol), 0) AS v
        FROM $table
        WHERE YEAR($dateCol) = $currentYear
    ");

    $prev = singleValue($conn, "
        SELECT COALESCE(SUM($amountCol), 0) AS v
        FROM $table
        WHERE YEAR($dateCol) = $lastYear
    ");

    $diff = $cur - $prev;
    $pct = $prev > 0 ? round(($diff / $prev) * 100, 1) : 0;

    $gtCur += $cur;
    $gtPrev += $prev;

    $yearRows[] = [
        'name' => $name,
        'cur' => $cur,
        'prev' => $prev,
        'diff' => $diff,
        'pct' => $pct,
    ];
}

$gtDiff = $gtCur - $gtPrev;
$gtPct = $gtPrev > 0 ? round(($gtDiff / $gtPrev) * 100, 1) : 0;

$projs = mysqli_query($conn, "
    SELECT 
        p.name,
        p.type,
        p.target_amount,
        p.status,
        COALESCE(SUM(c.amount), 0) AS raised
    FROM projects p
    LEFT JOIN contributions c ON c.project_id = p.id
    GROUP BY p.id, p.name, p.type, p.target_amount, p.status
    ORDER BY p.name ASC
");

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Fund Balance Summary</title>
<style>
body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#111;padding:20px}
.hdr{border-bottom:3px solid #28a745;padding-bottom:12px;margin-bottom:18px}
.hdr h1{color:#4a1e8f;font-size:15px;margin:2px 0}
.hdr h2{color:#28a745;font-size:13px;margin:2px 0}
.meta{float:right;font-size:9px;color:#888;text-align:right}
table{width:100%;border-collapse:collapse;margin-bottom:18px;font-size:10px}
th{background:#4a1e8f;color:#fff;padding:6px 8px;text-align:left}
td{padding:5px 8px;border-bottom:1px solid #eee}
.grp{background:#e8f5e9;font-weight:bold;color:#28a745;padding:6px 8px;font-size:11px}
.tot td{background:#28a745;color:#fff;font-weight:bold;font-size:12px;padding:7px 8px}
.liab td{background:#4a1e8f;color:#fff;font-weight:bold;padding:7px 8px}
.net-pos td{background:#28a745;color:#fff;font-weight:bold;font-size:13px;padding:9px}
.net-neg td{background:#dc3545;color:#fff;font-weight:bold;font-size:13px;padding:9px}
.r{text-align:right}
.indent{padding-left:18px!important}
.note{background:#fff9e6;border-left:4px solid #d4a017;padding:8px;font-size:9px;margin-bottom:12px;color:#777}
.cmp-head td{background:#4a1e8f;color:#fff;font-weight:bold}
.footer{font-size:8px;color:#aaa;text-align:center;margin-top:16px;border-top:1px solid #eee;padding-top:8px}
.sig-block{display:table;width:100%;margin-top:28px}
.sig-cell{display:table-cell;width:33%;padding:0 8px;text-align:center}
.sig-line{border-top:1px solid #4a1e8f;padding-top:4px;font-size:9px;color:#666}
</style>
</head>

<body>

<div class="hdr">
  <div class="meta">
    As at: <?= e(fmtDate($to)) ?><br>
    Generated: <?= e($generated) ?>
  </div>

 
  <h2>CHURCH FUND BALANCE &amp; FINANCIAL SUMMARY</h2>
  
</div>

<div class="note">
  This statement presents the cumulative financial position of the church as at
  <strong><?= e(fmtDate($to)) ?></strong>. All figures are in Uganda Shillings (UGX).
</div>

<table>
<thead>
<tr>
  <th colspan="3">STATEMENT OF FUNDS - CUMULATIVE TO <?= e(strtoupper(fmtDate($to))) ?></th>
</tr>
</thead>

<tbody>
<tr>
  <td colspan="3" class="grp">I. TOTAL FUNDS RECEIVED</td>
</tr>

<tr><td class="indent">Offertory Collections</td><td></td><td class="r"><?= money($all_offertory) ?></td></tr>
<tr><td class="indent">Tithe Received</td><td></td><td class="r"><?= money($all_tithe) ?></td></tr>
<tr><td class="indent">Thanksgiving Offerings</td><td></td><td class="r"><?= money($all_thanksgiving) ?></td></tr>
<tr><td class="indent">Project Contributions</td><td></td><td class="r"><?= money($all_contributions) ?></td></tr>
<tr><td class="indent">Pledge Receipts</td><td></td><td class="r"><?= money($all_pledges_paid) ?></td></tr>
<tr><td class="indent">Fundraising Collections</td><td></td><td class="r"><?= money($all_fundraising) ?></td></tr>

<tr class="tot">
  <td>TOTAL FUNDS RECEIVED</td>
  <td></td>
  <td class="r"><?= money($total_income_alltime) ?></td>
</tr>

<tr>
  <td colspan="3" class="grp">II. COMMITTED FUNDS &amp; BUDGETS</td>
</tr>

<tr><td class="indent">Active Project Budgets</td><td></td><td class="r"><?= money($project_budgets) ?></td></tr>
<tr><td class="indent">Outstanding Pledge Commitments</td><td></td><td class="r"><?= money($pledges_outstanding) ?></td></tr>

<tr class="liab">
  <td>TOTAL COMMITMENTS</td>
  <td></td>
  <td class="r"><?= money($total_commitments) ?></td>
</tr>

<tr>
  <td colspan="3" style="padding:4px"></td>
</tr>

<tr class="<?= $net_balance >= 0 ? 'net-pos' : 'net-neg' ?>">
  <td colspan="2"><?= $net_balance >= 0 ? 'NET SURPLUS BALANCE (Funds available)' : 'NET DEFICIT (Commitments exceed receipts)' ?></td>
  <td class="r">
    <?= $net_balance < 0 ? '(' : '' ?>UGX <?= money(abs($net_balance)) ?><?= $net_balance < 0 ? ')' : '' ?>
  </td>
</tr>
</tbody>
</table>

<table>
<thead>
<tr>
  <th colspan="4">YEAR-ON-YEAR INCOME COMPARISON</th>
</tr>
<tr class="cmp-head">
  <td>Income Source</td>
  <td class="r"><?= e($currentYear) ?></td>
  <td class="r"><?= e($lastYear) ?></td>
  <td class="r">Change</td>
</tr>
</thead>

<tbody>
<?php foreach ($yearRows as $row): ?>
<tr>
  <td><?= e($row['name']) ?></td>
  <td class="r"><?= money($row['cur']) ?></td>
  <td class="r"><?= money($row['prev']) ?></td>
  <td class="r" style="color:<?= $row['diff'] >= 0 ? 'green' : 'red' ?>">
    <?= $row['diff'] >= 0 ? '+' : '' ?><?= money($row['diff']) ?> (<?= e($row['pct']) ?>%)
  </td>
</tr>
<?php endforeach; ?>
</tbody>

<tfoot>
<tr style="background:#4a1e8f;color:#fff;font-weight:bold">
  <td>TOTAL</td>
  <td class="r"><?= money($gtCur) ?></td>
  <td class="r"><?= money($gtPrev) ?></td>
  <td class="r"><?= $gtDiff >= 0 ? '+' : '' ?><?= money($gtDiff) ?> (<?= e($gtPct) ?>%)</td>
</tr>
</tfoot>
</table>

<table>
<thead>
<tr>
  <th colspan="5">PROJECT FUNDING STATUS</th>
</tr>
<tr style="background:#4a1e8f;color:#fff">
  <td>Project</td>
  <td>Type</td>
  <td class="r">Budget</td>
  <td class="r">Raised</td>
  <td class="r">Remaining</td>
</tr>
</thead>

<tbody>
<?php if ($projs && mysqli_num_rows($projs) > 0): ?>
  <?php while ($p = mysqli_fetch_assoc($projs)): ?>
    <?php
      $target = (float)($p['target_amount'] ?? 0);
      $raised = (float)($p['raised'] ?? 0);
      $remaining = $target - $raised;
    ?>
    <tr>
      <td><?= e($p['name'] ?? '-') ?></td>
      <td><?= e($p['type'] ?? '-') ?></td>
      <td class="r"><?= money($target) ?></td>
      <td class="r" style="color:green"><?= money($raised) ?></td>
      <td class="r" style="color:<?= $remaining > 0 ? 'red' : 'green' ?>">
        <?= $remaining > 0 ? money($remaining) : 'FULLY FUNDED' ?>
      </td>
    </tr>
  <?php endwhile; ?>
<?php else: ?>
  <tr>
    <td colspan="5" style="text-align:center">No project records found.</td>
  </tr>
<?php endif; ?>
</tbody>
</table>

<div class="sig-block">
  <div class="sig-cell"><div class="sig-line">Prepared by: _______________<br>Name &amp; Signature / Date</div></div>
  <div class="sig-cell"><div class="sig-line">Reviewed by: _______________<br>Treasurer / Date</div></div>
  <div class="sig-cell"><div class="sig-line">Approved by: _______________<br>Vicar / Date</div></div>
</div>

<div class="footer">
  Fund Balance Summary - As at <?= e(fmtDate($to)) ?> - <?= e($generated) ?>
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
    $dompdf->stream('Fund_Balance_Summary_' . date('Ymd') . '.pdf', ['Attachment' => true]);
    exit;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<div class="page-header">
  <h2><i class="fas fa-balance-scale"></i> Fund Balance Summary</h2>

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
      <label class="mr-1">As At:</label>
      <input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm mr-2">

      <button class="btn btn-sm btn-secondary">
        <i class="fas fa-filter"></i> Apply
      </button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <iframe srcdoc="<?= e($html) ?>" style="width:100%;min-height:900px;border:none"></iframe>
  </div>
</div>

</div>
</div>

<?php include '../includes/footer.php'; ?>
