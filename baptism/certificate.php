<?php
require_once '../config/config.php';
requireLogin();

$id = intval($_GET['id'] ?? 0);

$r = fetchOne("
    SELECT 
        b.*,
        CONCAT(m.first_name,' ',m.last_name) AS member_name
    FROM baptisms b
    LEFT JOIN members m ON m.id = b.member_id
    WHERE b.id = $id
");

if (!$r) {
    setFlash('Record not found.', 'danger');
    redirect('index.php');
}

$isPdf = isset($_GET['pdf']);
if ($isPdf) require_permission('baptism.view');
$generated = date('d M Y');
$certificateBrand = [
    'name' => defined('APP_NAME') ? APP_NAME : 'Church',
    'subtitle' => '',
    'logo' => ''
];
try {
    $brandResult = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('site_name','site_subtitle','site_logo')");
    if ($brandResult) {
        while ($brandRow = $brandResult->fetch_assoc()) {
            $brandValue = trim((string)$brandRow['setting_value']);
            if ($brandRow['setting_key'] === 'site_name' && $brandValue !== '') $certificateBrand['name'] = $brandValue;
            if ($brandRow['setting_key'] === 'site_subtitle') $certificateBrand['subtitle'] = $brandValue;
            if ($brandRow['setting_key'] === 'site_logo') $certificateBrand['logo'] = $brandValue;
        }
        $brandResult->close();
    }
} catch (Throwable $e) {
    error_log('[baptism certificate branding] ' . $e->getMessage());
}
$certificateLogoSrc = '';
if ($certificateBrand['logo'] !== '') {
    if (preg_match('~^https?://~i', $certificateBrand['logo'])) {
        $certificateLogoSrc = $certificateBrand['logo'];
    } else {
        $logoRelative = ltrim($certificateBrand['logo'], '/');
        $logoAbsolute = __DIR__ . '/../' . $logoRelative;
        if (is_file($logoAbsolute)) {
            $logoData = @file_get_contents($logoAbsolute);
            if ($logoData !== false) {
                $logoMime = function_exists('mime_content_type') ? (mime_content_type($logoAbsolute) ?: 'image/png') : 'image/png';
                $certificateLogoSrc = 'data:' . $logoMime . ';base64,' . base64_encode($logoData);
            }
        } else {
            $certificateLogoSrc = rtrim(BASE_URL, '/') . '/' . $logoRelative;
        }
    }
}

$signatories = [
    'vicar_name' => '',
    'vicar_signature' => '',
    'secretary_name' => '',
    'secretary_signature' => ''
];

try {
    $conn->query("CREATE TABLE IF NOT EXISTS baptism_certificate_signatories (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        vicar_name VARCHAR(150) NULL,
        vicar_signature VARCHAR(255) NULL,
        secretary_name VARCHAR(150) NULL,
        secretary_signature VARCHAR(255) NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("INSERT IGNORE INTO baptism_certificate_signatories (id) VALUES (1)");
    $sigRes = $conn->query("SELECT vicar_name, vicar_signature, secretary_name, secretary_signature FROM baptism_certificate_signatories WHERE id=1 LIMIT 1");
    if ($sigRes && ($sigRow = $sigRes->fetch_assoc())) {
        $signatories = array_merge($signatories, $sigRow);
    }
    if ($sigRes) $sigRes->close();
} catch (Throwable $e) {
    error_log('[baptism certificate signatories] ' . $e->getMessage());
}

function baptism_signature_src(string $relative): string {
    $relative = trim($relative);
    if ($relative === '' || strpos($relative, 'uploads/baptism-signatures/') !== 0) return '';
    $absolute = __DIR__ . '/../' . $relative;
    if (!is_file($absolute)) return '';
    $mime = function_exists('mime_content_type') ? mime_content_type($absolute) : 'image/png';
    $data = @file_get_contents($absolute);
    if ($data === false) return '';
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

$vicarSignatureSrc = baptism_signature_src((string)$signatories['vicar_signature']);
$secretarySignatureSrc = baptism_signature_src((string)$signatories['secretary_signature']);

ob_start();
?>
<?php if (!$isPdf): ?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content baptism-page">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php else: ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Baptism Certificate</title></head><body style="background:#fff">
<?php endif; ?>


<style>
@page{
    size:A4 portrait;
    margin:8mm;
}

html,body{
    margin:0;
    padding:0;
    background:#ece9e2;
    font-family:"Times New Roman", Georgia, serif;
}

/* top buttons */
.no-print{
    text-align:center;
    padding:15px 10px;
}

.btn{
    display:inline-block;
    padding:10px 24px;
    margin:4px;
    border-radius:4px;
    text-decoration:none;
    color:#fff;
    font-size:15px;
    border:none;
    cursor:pointer;
}

.btn-print{background:#4b27a8;}
.btn-pdf{background:#d93444;}
.btn-back{background:#66707c;}

/* certificate */
.cert{
    width:190mm;
    min-height:270mm;
    margin:0 auto 15px auto;
    background:#fff;
    border:4px double #4b27a8;
    padding:12mm;
    box-sizing:border-box;
    page-break-inside:avoid;
    break-inside:avoid;
}

.cert-inner{
    min-height:245mm;
    border:2px solid #d4a017;
    padding:12mm;
    box-sizing:border-box;
    text-align:center;
    position:relative;
}

.certificate-watermark{
    position:absolute;
    z-index:0;
    top:50%;
    left:50%;
    width:65%;
    height:55%;
    object-fit:contain;
    opacity:.055;
    transform:translate(-50%,-50%);
}
.cert-inner > :not(.certificate-watermark){position:relative;z-index:1}

.cross{
    font-size:34px;
    color:#4b27a8;
    line-height:1;
    margin-bottom:4px;
}

.certificate-logo{
    display:block;
    max-width:150px;
    max-height:85px;
    width:auto;
    height:auto;
    object-fit:contain;
    margin:0 auto 10px;
}

.church-name{
    font-size:20px;
    color:#4b27a8;
    font-weight:bold;
    letter-spacing:1px;
}

.diocese{
    font-size:13px;
    color:#555;
    margin-bottom:12px;
}

h1{
    margin:8px 0 6px;
    color:#d4a017;
    font-size:30px;
    letter-spacing:1px;
}

.subtitle{
    color:#4b27a8;
    font-style:italic;
    font-size:18px;
    margin-bottom:16px;
}

.candidate{
    display:inline-block;
    font-size:34px;
    color:#4b27a8;
    font-weight:bold;
    border-bottom:2px solid #d4a017;
    padding:0 18px 6px;
    margin-bottom:14px;
}

.cert-body{
    font-size:23px;
    line-height:1.65;
    color:#111;
    margin-bottom:18px;
}

.details{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
    margin-bottom:24px;
}

.details td{
    width:50%;
    padding:8px 6px;
    vertical-align:top;
    border-bottom:1px dotted #ccc;
}

.label{
    display:block;
    font-size:12px;
    color:#777;
    text-transform:uppercase;
    margin-bottom:3px;
}

.value{
    font-size:16px;
    font-weight:bold;
    color:#111;
}

.signatures{
    width:100%;
    margin-top:30px;
}

.signatures td{
    width:50%;
    padding:0 10px;
    text-align:center;
    vertical-align:top;
}

.signature-image{
    display:block;
    width:180px;
    height:70px;
    object-fit:contain;
    margin:0 auto 2px;
}
.signature-space{
    height:70px;
}
.signatory-name{
    font-size:14px;
    font-weight:bold;
    min-height:18px;
    margin-bottom:2px;
}
.sig-line{
    border-top:1px solid #4b27a8;
    padding-top:6px;
    font-size:14px;
}

.footer-note{
    position:absolute;
    left:0;
    right:0;
    bottom:8mm;
    font-size:11px;
    color:#777;
}
.cert-inner > .footer-note{position:absolute;left:0;right:0;bottom:8mm}

@media print{
    body{
        background:#fff;
    }

    .no-print{
        display:none !important;
    }

    .cert{
        margin:0 auto;
        box-shadow:none;
    }
}
</style>


<?php if(!$isPdf): ?>
<div class="no-print">
    <button onclick="window.print()" class="btn btn-print">Print</button>

    <?php if(file_exists(__DIR__.'/../vendor/autoload.php')): ?>
        <a href="?id=<?= $id ?>&pdf=1" class="btn btn-pdf">Download PDF</a>
    <?php endif; ?>

    <a href="index.php" class="btn btn-back">Back</a>
</div>
<?php endif; ?>

<div class="cert">
<div class="cert-inner">

<?php if($certificateLogoSrc !== ''): ?><img class="certificate-watermark" src="<?= htmlspecialchars($certificateLogoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true"><?php endif; ?>
<?php if($certificateLogoSrc !== ''): ?><img class="certificate-logo" src="<?= htmlspecialchars($certificateLogoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($certificateBrand['name'], ENT_QUOTES, 'UTF-8') ?> logo"><?php endif; ?>
<div class="church-name"><?= htmlspecialchars($certificateBrand['name'], ENT_QUOTES, 'UTF-8') ?></div>
<?php if($certificateBrand['subtitle'] !== ''): ?><div class="diocese"><?= htmlspecialchars($certificateBrand['subtitle'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<h1>BAPTISM CERTIFICATE</h1>

<div class="subtitle">This is to certify that</div>

<div class="candidate">
<?= htmlspecialchars($r['candidate_name']) ?>
</div>

<div class="cert-body">
<?php if($r['baptism_type'] === 'Infant'): ?>

was received into the Holy Sacrament of
<strong>Infant Baptism</strong>

<?php elseif($r['baptism_type'] === 'Confirmation'): ?>

was <strong>Confirmed</strong> in the Faith

<?php else: ?>

received the Holy Sacrament of
<strong><?= htmlspecialchars($r['baptism_type']) ?> Baptism</strong>

<?php endif; ?>

<br><br>

at <strong><?= htmlspecialchars($r['venue']) ?></strong>
</div>

<table class="details">
<tr>
<td>
<span class="label">Date of Baptism</span>
<span class="value"><?= date('d F Y', strtotime($r['baptism_date'])) ?></span>
</td>

<td>
<span class="label">Officiant</span>
<span class="value"><?= htmlspecialchars($r['officiant'] ?: '-') ?></span>
</td>
</tr>

<tr>
<td>
<span class="label">Godfather</span>
<span class="value"><?= htmlspecialchars($r['godfather'] ?: '-') ?></span>
</td>

<td>
<span class="label">Godmother</span>
<span class="value"><?= htmlspecialchars($r['godmother'] ?: '-') ?></span>
</td>
</tr>

<tr>
<td>
<span class="label">Father</span>
<span class="value"><?= htmlspecialchars($r['parent_father'] ?: '-') ?></span>
</td>

<td>
<span class="label">Mother</span>
<span class="value"><?= htmlspecialchars($r['parent_mother'] ?: '-') ?></span>
</td>
</tr>
</table>

<table class="signatures">
<tr>
<td>
<?php if($vicarSignatureSrc !== ''): ?>
<img class="signature-image" src="<?= htmlspecialchars($vicarSignatureSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Vicar signature">
<?php else: ?>
<div class="signature-space"></div>
<?php endif; ?>
<div class="signatory-name"><?= htmlspecialchars((string)($signatories['vicar_name'] ?: $r['officiant'] ?: ''), ENT_QUOTES, 'UTF-8') ?></div>
<div class="sig-line">The Vicar / Officiating Clergy</div>
</td>

<td>
<?php if($secretarySignatureSrc !== ''): ?>
<img class="signature-image" src="<?= htmlspecialchars($secretarySignatureSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Secretary signature">
<?php else: ?>
<div class="signature-space"></div>
<?php endif; ?>
<div class="signatory-name"><?= htmlspecialchars((string)($signatories['secretary_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
<div class="sig-line">Church Secretary</div>
</td>
</tr>
</table>

<div class="footer-note">
Baptism No: <?= htmlspecialchars($r['baptism_no']) ?>

<?php if(!empty($r['certificate_no'])): ?>
 | Cert No: <?= htmlspecialchars($r['certificate_no']) ?>
<?php endif; ?>

 | Printed: <?= $generated ?>
</div>

<?php if (!$isPdf): ?>
</div>
</div>
<?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

/* PDF DOWNLOAD */
if($isPdf){

    $autoload = __DIR__.'/../vendor/autoload.php';

    if(file_exists($autoload)){

        require_once $autoload;

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Times-Roman');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dompdf->stream(
            'Baptism_Certificate_'.$id.'.pdf',
            ['Attachment' => true]
        );
        exit;
    }
}

echo $html;
?>
