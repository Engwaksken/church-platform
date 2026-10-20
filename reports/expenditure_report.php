<?php
require_once '../config/config.php';
requireLogin();

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v): string {
    return number_format((float)$v);
}

$generated = date('d F Y, H:i');
$conn->query("CREATE TABLE IF NOT EXISTS expenses (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, expense_date DATE NOT NULL, category VARCHAR(120) NOT NULL, description VARCHAR(255) NOT NULL, amount DECIMAL(14,2) NOT NULL, paid_to VARCHAR(150) DEFAULT NULL, payment_method VARCHAR(60) DEFAULT NULL, reference_no VARCHAR(100) DEFAULT NULL, notes TEXT DEFAULT NULL, created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_expense_date (expense_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$expenseRows = $conn->query("SELECT expense_date,category,description,paid_to,payment_method,reference_no,amount FROM expenses ORDER BY expense_date DESC,id DESC");
$expenseTotalRow = $conn->query("SELECT COALESCE(SUM(amount),0) total FROM expenses")->fetch_assoc();
$expenseTotal = (float)($expenseTotalRow['total'] ?? 0);

$projects = mysqli_query($conn, "
    SELECT 
        p.*,
        COALESCE(SUM(c.amount), 0) AS raised
    FROM projects p
    LEFT JOIN contributions c ON c.project_id = p.id
    GROUP BY p.id
    ORDER BY p.type ASC, p.name ASC
");

$totalBudgetsRes = mysqli_query($conn, "SELECT COALESCE(SUM(target_amount), 0) AS total FROM projects");
$totalBudgetsRow = $totalBudgetsRes ? mysqli_fetch_assoc($totalBudgetsRes) : ['total' => 0];
$total_budgets = (float)($totalBudgetsRow['total'] ?? 0);

$totalRaisedRes = mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) AS total FROM contributions");
$totalRaisedRow = $totalRaisedRes ? mysqli_fetch_assoc($totalRaisedRes) : ['total' => 0];
$total_raised = (float)($totalRaisedRow['total'] ?? 0);

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Expenditure Report</title>
<style>
body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#111;padding:20px}
.hdr{border-bottom:3px solid #dc3545;padding-bottom:10px;margin-bottom:15px}
.hdr h1{color:#4a1e8f;font-size:15px;margin:2px 0}
.hdr h2{color:#dc3545;font-size:12px;margin:2px 0}
.meta{float:right;font-size:9px;color:#888;text-align:right}
table{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:10px}
th{background:#4a1e8f;color:#fff;padding:5px 8px;text-align:left}
td{padding:5px 8px;border-bottom:1px solid #eee}
tr:nth-child(even) td{background:#fff5f5}
.sec td{background:#f8d7da;font-weight:bold;color:#dc3545;padding:5px 8px}
.tot td{background:#dc3545;color:#fff;font-weight:bold}
.r{text-align:right}
.bar-wrap{background:#eee;border-radius:4px;height:10px;min-width:80px}
.bar-fill{background:#28a745;height:10px;border-radius:4px}
.footer{font-size:8px;color:#aaa;text-align:center;margin-top:12px}
.status-pill{font-size:8px;padding:1px 4px;border-radius:3px}
.status-active{background:#d4edda;color:#155724}
.status-other{background:#e2e3e5;color:#383d41}
</style>
</head>

<body>

<div class="hdr">
  <div class="meta">Generated: <?= e($generated) ?></div>
  <h2>EXPENDITURE &amp; PROJECT BUDGET REPORT</h2>
</div>

<table>
<thead>
<tr><th colspan="5">ALL PROJECTS - BUDGET vs. FUNDS RAISED</th></tr>
<tr class="sec">
  <td>Project Name</td>
  <td>Type</td>
  <td class="r">Budget (UGX)</td>
  <td class="r">Raised (UGX)</td>
  <td>Progress</td>
</tr>
</thead>

<tbody>
<?php if ($projects && mysqli_num_rows($projects) > 0): ?>
    <?php $curType = ''; ?>
    <?php while ($p = mysqli_fetch_assoc($projects)): ?>
        <?php
        $type = $p['type'] ?? 'General';

        if ($curType !== $type):
            $curType = $type;
        ?>
            <tr>
                <td colspan="5" style="background:#f0f0f0;font-weight:bold;color:#555;font-size:10px;padding:4px 8px">
                    - <?= e($curType) ?> -
                </td>
            </tr>
        <?php endif; ?>

        <?php
        $target = (float)($p['target_amount'] ?? 0);
        $raised = (float)($p['raised'] ?? 0);
        $pct = $target > 0 ? min(100, round(($raised / $target) * 100)) : 0;
        $status = $p['status'] ?? 'Unknown';
        ?>

        <tr>
            <td>
                <?= e($p['name'] ?? '-') ?>
                <span class="status-pill <?= $status === 'Active' ? 'status-active' : 'status-other' ?>">
                    <?= e($status) ?>
                </span>
            </td>
            <td><?= e($type) ?></td>
            <td class="r"><?= money($target) ?></td>
            <td class="r" style="color:green"><?= money($raised) ?></td>
            <td>
                <div class="bar-wrap">
                    <div class="bar-fill" style="width:<?= $pct ?>%"></div>
                </div>
                <small><?= $pct ?>%</small>
            </td>
        </tr>
    <?php endwhile; ?>
<?php else: ?>
    <tr>
        <td colspan="5" style="text-align:center">No project records found.</td>
    </tr>
<?php endif; ?>
</tbody>

<tfoot>
<tr class="tot">
  <td colspan="2">TOTAL</td>
  <td class="r"><?= money($total_budgets) ?></td>
  <td class="r"><?= money($total_raised) ?></td>
  <td><?= $total_budgets > 0 ? round(($total_raised / $total_budgets) * 100) . '%' : '0%' ?></td>
</tr>
</tfoot>
</table>

<table><thead><tr><th colspan="7">RECORDED EXPENSES</th></tr><tr><th>Date</th><th>Category</th><th>Description</th><th>Paid to</th><th>Method</th><th>Reference</th><th class="r">Amount (UGX)</th></tr></thead><tbody>
<?php if ($expenseRows && $expenseRows->num_rows): while ($ex = $expenseRows->fetch_assoc()): ?><tr><td><?=e($ex['expense_date'])?></td><td><?=e($ex['category'])?></td><td><?=e($ex['description'])?></td><td><?=e($ex['paid_to'])?></td><td><?=e($ex['payment_method'])?></td><td><?=e($ex['reference_no'])?></td><td class="r"><?=money($ex['amount'])?></td></tr><?php endwhile; else: ?><tr><td colspan="7" style="text-align:center">No expenses have been recorded.</td></tr><?php endif; ?></tbody><tfoot><tr class="tot"><td colspan="6">TOTAL RECORDED EXPENSES</td><td class="r"><?=money($expenseTotal)?></td></tr></tfoot></table>

<div class="footer">
  Expenditure Report - <?= e($generated) ?>
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
    $dompdf->stream('Expenditure_Report_' . date('Ymd') . '.pdf', ['Attachment' => true]);
    exit;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<div class="page-header">
  <h2><i class="fas fa-receipt"></i> Expenditure Report</h2>

  <div>
    <a href="finance.php" class="btn btn-secondary mr-1">
      <i class="fas fa-arrow-left"></i> Back
    </a>

    <button onclick="window.print()" class="btn btn-primary mr-1">
      <i class="fas fa-print"></i> Print
    </button>

    <a href="?pdf=1" class="btn btn-danger">
      <i class="fas fa-file-pdf"></i> PDF
    </a>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <iframe srcdoc="<?= e($html) ?>" style="width:100%;min-height:650px;border:none"></iframe>
  </div>
</div>

</div>
</div>

<?php include '../includes/footer.php'; ?>
