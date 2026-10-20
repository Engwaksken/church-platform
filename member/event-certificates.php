<?php
declare(strict_types=1);
$pageTitle = 'My Event Certificates';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../events/certificate-functions.php';
require_permission('events.view');
$memberId = current_member_id();
$certificates=[];
if(event_certificate_tables_ready($conn)){
    $stmt = $conn->prepare('SELECT ec.id,ec.certificate_no,ec.issued_at,e.title,e.event_type,e.event_date,e.venue FROM event_certificates ec JOIN events e ON e.id=ec.event_id JOIN event_attendance ea ON ea.event_id=ec.event_id AND ea.member_id=ec.member_id AND ea.attended=1 WHERE ec.member_id=? ORDER BY ec.issued_at DESC');
    $stmt->bind_param('i',$memberId);$stmt->execute();$result=$stmt->get_result();while($row=$result->fetch_assoc())$certificates[]=$row;$stmt->close();
}
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="main-content"><?php include __DIR__ . '/../includes/topbar.php';?><div class="content-area">
<div class="page-header"><h2><i class="fas fa-certificate"></i> My Event Certificates</h2><p class="text-muted">Certificates issued for events where your attendance was recorded.</p></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Event</th><th>Type</th><th>Event date</th><th>Certificate no.</th><th>Issued</th><th>Action</th></tr></thead><tbody>
<?php if(!$certificates):?><tr><td colspan="6" class="text-center text-muted">No event certificates have been issued to you yet.</td></tr><?php else:foreach($certificates as $certificate):?><tr><td><?=htmlspecialchars((string)$certificate['title'],ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars((string)$certificate['event_type'],ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars(date('d M Y',strtotime((string)$certificate['event_date'])),ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars((string)$certificate['certificate_no'],ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars(date('d M Y',strtotime((string)$certificate['issued_at'])),ENT_QUOTES,'UTF-8')?></td><td><a class="btn btn-primary btn-sm" href="<?=htmlspecialchars(BASE_URL.'events/certificate.php?certificate_id='.(int)$certificate['id'],ENT_QUOTES,'UTF-8')?>"><i class="fas fa-eye"></i> View</a> <a class="btn btn-outline-secondary btn-sm" href="<?=htmlspecialchars(BASE_URL.'events/certificate.php?certificate_id='.(int)$certificate['id'].'&pdf=1',ENT_QUOTES,'UTF-8')?>"><i class="fas fa-download"></i> Download PDF</a></td></tr><?php endforeach;endif;?>
</tbody></table></div></div>
</div></div><?php require_once __DIR__ . '/../includes/footer.php'; ?>
