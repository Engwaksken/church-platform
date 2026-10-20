<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../includes/church-book-functions.php';
require_once 'report-data.php';
requireLogin();
cb_require_permission('converts.view');
if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');
$rows = cn_report_rows($conn);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="converts-nurture-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['Conversion Date','Full Name','Person Type','Linked Record','Gender','Phone','Email','Decision Type','Service/Event/Place','Assigned Nurture Member','Nurture Start','Nurture End','Weeks','Status','Next Follow-up','Follow-up Count','Baptism Interest','Discipleship Class','Prayer Request','Testimony','Notes','Self Registered']);
foreach ($rows as $r) {
    $linked = $r['person_type'] === 'Member'
        ? trim((string)($r['linked_member_no'] ?? '') . ' ' . (string)($r['linked_member_name'] ?? ''))
        : (string)($r['linked_visitor_name'] ?? '');
    fputcsv($out, [
        $r['conversion_date'],$r['full_name'],$r['person_type'],$linked,$r['gender'],$r['phone'],$r['email'],$r['decision_type'],$r['conversion_context'],
        $r['assigned_member_name'],$r['nurture_start_date'],$r['nurture_end_date'],$r['nurture_period_weeks'],$r['status'],$r['next_follow_up_date'],$r['followup_count'],
        (int)$r['baptism_interest']===1?'Yes':'No',$r['discipleship_class'],$r['prayer_request'],$r['testimony'],$r['notes'],(int)$r['self_registered']===1?'Yes':'No'
    ]);
}
fclose($out);
exit;
