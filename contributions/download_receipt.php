<?php
require_once '../config/config.php';
if (function_exists('requireLogin')) requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function cr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cr_money($amount) { return 'UGX ' . number_format((float)$amount, 0); }
function cr_autoload_path() {
    $paths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
    ];
    foreach ($paths as $path) {
        if ($path && is_file($path)) return $path;
    }
    return '';
}
function cr_logo_data_uri() {
    $paths = [
        __DIR__ . '/../assets/img/logo.png',
        __DIR__ . '/../../assets/img/logo.png',
        dirname(__DIR__) . '/assets/img/logo.png',
        $_SERVER['DOCUMENT_ROOT'] . '/assets/img/logo.png',
    ];
    foreach ($paths as $path) {
        if ($path && is_file($path)) {
            $type = pathinfo($path, PATHINFO_EXTENSION) ?: 'png';
            return 'data:image/' . $type . ';base64,' . base64_encode(file_get_contents($path));
        }
    }
    return '';
}
function cr_amount_words($number) {
    $number = (int)round((float)$number);
    if (class_exists('NumberFormatter')) {
        $fmt = new NumberFormatter('en', NumberFormatter::SPELLOUT);
        return ucwords($fmt->format($number)) . ' Uganda Shillings Only';
    }
    return number_format($number) . ' Uganda Shillings Only';
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die('Invalid contribution ID.');

$sql = "SELECT c.*, CONCAT(COALESCE(m.first_name,''),' ',COALESCE(m.last_name,'')) AS member_name,
               m.member_no, m.phone, m.email, p.name AS project_name
        FROM contributions c
        LEFT JOIN members m ON m.id = c.member_id
        LEFT JOIN projects p ON p.id = c.project_id
        WHERE c.id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$receipt) die('Contribution not found.');

$autoload = cr_autoload_path();
if ($autoload === '') {
    die('Dompdf autoload not found. Please install Dompdf using Composer and ensure vendor/autoload.php exists.');
}
require_once $autoload;

if (!class_exists('Dompdf\\Dompdf')) {
    die('Dompdf class not found. Please confirm dompdf/dompdf is installed.');
}

$logo = cr_logo_data_uri();
$receiptNo = $receipt['receipt_no'] ?: ('CONTR-' . str_pad((string)$receipt['id'], 5, '0', STR_PAD_LEFT));
$date = !empty($receipt['contributed_on']) ? date('d M Y', strtotime($receipt['contributed_on'])) : date('d M Y');
$issued = date('d M Y h:i A');
$member = trim((string)($receipt['member_name'] ?? '')) ?: 'Walk-in / Unknown';
$amount = (float)($receipt['amount'] ?? 0);

$html = '
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { margin: 24px; }
body { font-family: DejaVu Sans, Arial, sans-serif; color:#111827; font-size:12px; }
.receipt { border:1px solid #d1d5db; padding:22px; position:relative; min-height:680px; }
.watermark { position:absolute; top:250px; left:70px; right:70px; text-align:center; font-size:58px; color:#f3f4f6; transform:rotate(-25deg); z-index:0; font-weight:800; }
.content { position:relative; z-index:1; }
.header { display:table; width:100%; border-bottom:3px solid #16a34a; padding-bottom:14px; margin-bottom:18px; }
.logo { display:table-cell; width:90px; vertical-align:top; }
.logo img { width:76px; height:76px; object-fit:contain; }
.org { display:table-cell; vertical-align:top; }
.org h1 { margin:0; font-size:21px; color:#14532d; }
.org p { margin:3px 0; color:#4b5563; }
.title { display:table-cell; width:210px; text-align:right; vertical-align:top; }
.title h2 { margin:0; font-size:22px; color:#15803d; }
.title .no { margin-top:6px; font-weight:700; color:#111827; }
.status { display:inline-block; padding:5px 10px; border-radius:999px; background:#dcfce7; color:#166534; font-weight:700; margin-top:8px; }
.section-title { font-size:13px; font-weight:800; color:#14532d; margin:18px 0 8px; border-bottom:1px solid #e5e7eb; padding-bottom:5px; }
.grid { display:table; width:100%; border-collapse:collapse; }
.row { display:table-row; }
.cell { display:table-cell; width:50%; padding:8px 10px; border:1px solid #e5e7eb; vertical-align:top; }
.label { display:block; font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px; }
.value { font-size:13px; font-weight:700; color:#111827; }
.amount-box { margin:18px 0; padding:18px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; text-align:center; }
.amount-box .label { color:#166534; }
.amount-box .amount { font-size:30px; font-weight:900; color:#14532d; margin-top:4px; }
.words { font-size:12px; color:#374151; margin-top:6px; }
.notes { min-height:55px; padding:10px; border:1px solid #e5e7eb; background:#f9fafb; }
.signatures { display:table; width:100%; margin-top:55px; }
.sig { display:table-cell; width:50%; padding-right:30px; }
.line { border-top:1px solid #111827; padding-top:6px; font-weight:700; }
.footer { position:absolute; bottom:18px; left:22px; right:22px; border-top:1px solid #e5e7eb; padding-top:8px; font-size:10px; color:#6b7280; text-align:center; }
</style>
</head>
<body>
<div class="receipt">
  <div class="watermark">RECEIVED</div>
  <div class="content">
    <div class="header">
      <div class="logo">' . ($logo ? '<img src="' . $logo . '">' : '') . '</div>
      <div class="org">
        <h1>Rongoro COU Church</h1>
        <p>Church Contributions Management</p>
        <p>Official contribution receipt</p>
      </div>
      <div class="title">
        <h2>RECEIPT</h2>
        <div class="no">No: ' . cr_h($receiptNo) . '</div>
        <div class="status">PAID</div>
      </div>
    </div>

    <div class="section-title">Contributor Details</div>
    <div class="grid">
      <div class="row">
        <div class="cell"><span class="label">Member Name</span><span class="value">' . cr_h($member) . '</span></div>
        <div class="cell"><span class="label">Member No</span><span class="value">' . cr_h($receipt['member_no'] ?? '—') . '</span></div>
      </div>
      <div class="row">
        <div class="cell"><span class="label">Phone</span><span class="value">' . cr_h($receipt['phone'] ?? '—') . '</span></div>
        <div class="cell"><span class="label">Email</span><span class="value">' . cr_h($receipt['email'] ?? '—') . '</span></div>
      </div>
    </div>

    <div class="section-title">Contribution Details</div>
    <div class="grid">
      <div class="row">
        <div class="cell"><span class="label">Project</span><span class="value">' . cr_h($receipt['project_name'] ?? 'General Contribution') . '</span></div>
        <div class="cell"><span class="label">Payment Method</span><span class="value">' . cr_h($receipt['payment_method'] ?? 'Cash') . '</span></div>
      </div>
      <div class="row">
        <div class="cell"><span class="label">Contribution Date</span><span class="value">' . cr_h($date) . '</span></div>
        <div class="cell"><span class="label">Issued On</span><span class="value">' . cr_h($issued) . '</span></div>
      </div>
    </div>

    <div class="amount-box">
      <div class="label">Amount Received</div>
      <div class="amount">' . cr_h(cr_money($amount)) . '</div>
      <div class="words">' . cr_h(cr_amount_words($amount)) . '</div>
    </div>

    <div class="section-title">Notes</div>
    <div class="notes">' . nl2br(cr_h($receipt['notes'] ?? '')) . '</div>

    <div class="signatures">
      <div class="sig"><div class="line">Received By / Treasurer</div></div>
      <div class="sig"><div class="line">Authorized Signature</div></div>
    </div>
  </div>
  <div class="footer">This is a computer-generated receipt. Thank you for supporting the church work.</div>
</div>
</body>
</html>';

$options = new Dompdf\Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$filename = 'contribution-receipt-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $receiptNo) . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
exit;
