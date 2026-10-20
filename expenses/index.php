<?php
require_once '../config/config.php';
requireLogin();

$conn->query("CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    paid_to VARCHAR(150) DEFAULT NULL,
    payment_method VARCHAR(60) DEFAULT NULL,
    reference_no VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expense_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function ex_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim($_POST['expense_date'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    $paidTo = trim($_POST['paid_to'] ?? '');
    $method = trim($_POST['payment_method'] ?? '');
    $reference = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$category || !$description || $amount === false || $amount <= 0) {
        $error = 'Enter a valid date, category, description and positive amount.';
    } else {
        $stmt = $conn->prepare('INSERT INTO expenses (expense_date,category,description,amount,paid_to,payment_method,reference_no,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $stmt->bind_param('sssdssssi', $date, $category, $description, $amount, $paidTo, $method, $reference, $notes, $uid);
        if ($stmt->execute()) { setFlash('Expense recorded successfully.'); redirect('index.php'); }
        $error = 'Unable to record this expense.';
    }
}
$from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
$stmt = $conn->prepare('SELECT * FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date DESC,id DESC');
$stmt->bind_param('ss', $from, $to); $stmt->execute(); $expenses = $stmt->get_result();
$totalStmt = $conn->prepare('SELECT COALESCE(SUM(amount),0) total FROM expenses WHERE expense_date BETWEEN ? AND ?');
$totalStmt->bind_param('ss', $from, $to); $totalStmt->execute(); $total = (float)$totalStmt->get_result()->fetch_assoc()['total'];
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<div class="main-content"><?php include '../includes/topbar.php'; ?><div class="content-area">
<?php showFlash(); ?><div class="page-header"><h2><i class="fas fa-receipt"></i> Expenses</h2><a class="btn btn-outline-primary" href="../reports/expenditure_report.php"><i class="fas fa-chart-bar"></i> Expense Report</a></div>
<?php if ($error): ?><div class="alert alert-danger"><?=ex_h($error)?></div><?php endif; ?>
<div class="card mb-4"><div class="card-header">Record an Expense</div><div class="card-body"><form method="post" class="row">
<div class="col-md-3 mb-3"><label>Date</label><input type="date" name="expense_date" class="form-control" required value="<?=ex_h(date('Y-m-d'))?>"></div>
<div class="col-md-3 mb-3"><label>Category</label><input name="category" class="form-control" maxlength="120" required placeholder="Utilities, transport..."></div>
<div class="col-md-3 mb-3"><label>Description</label><input name="description" class="form-control" maxlength="255" required></div>
<div class="col-md-3 mb-3"><label>Amount (UGX)</label><input type="number" name="amount" class="form-control" min="0.01" step="0.01" required></div>
<div class="col-md-3 mb-3"><label>Paid to</label><input name="paid_to" class="form-control" maxlength="150"></div><div class="col-md-3 mb-3"><label>Payment method</label><input name="payment_method" class="form-control" maxlength="60" placeholder="Cash, bank, mobile money"></div>
<div class="col-md-3 mb-3"><label>Reference</label><input name="reference_no" class="form-control" maxlength="100"></div><div class="col-md-3 mb-3"><label>Notes</label><input name="notes" class="form-control"></div>
<div class="col-12"><button class="btn btn-primary"><i class="fas fa-save"></i> Save Expense</button></div></form></div></div>
<div class="card"><div class="card-body"><form class="form-inline mb-3" method="get"><label class="mr-2">From</label><input class="form-control mr-2" type="date" name="from" value="<?=ex_h($from)?>"><label class="mr-2">To</label><input class="form-control mr-2" type="date" name="to" value="<?=ex_h($to)?>"><button class="btn btn-secondary">Filter</button></form>
<h5>Total expenses: UGX <?=number_format($total,2)?></h5><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Paid to</th><th>Method</th><th class="text-right">Amount (UGX)</th></tr></thead><tbody>
<?php if (!$expenses->num_rows): ?><tr><td colspan="6" class="text-center text-muted">No expenses recorded for this period.</td></tr><?php else: while ($row=$expenses->fetch_assoc()): ?><tr><td><?=ex_h($row['expense_date'])?></td><td><?=ex_h($row['category'])?></td><td><?=ex_h($row['description'])?></td><td><?=ex_h($row['paid_to'])?></td><td><?=ex_h($row['payment_method'])?></td><td class="text-right"><?=number_format((float)$row['amount'],2)?></td></tr><?php endwhile; endif; ?>
</tbody></table></div></div></div></div></div><?php include '../includes/footer.php'; ?>
