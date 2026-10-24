<?php
declare(strict_types=1);

if (!function_exists('cn_report_rows')) {
    function cn_report_rows(mysqli $conn): array
    {
        $q = cb_clean($_GET['q'] ?? '');
        $type = cb_clean($_GET['type'] ?? '');
        $status = cb_clean($_GET['status'] ?? '');
        $assigned = cb_int($_GET['assigned'] ?? 0);
        $from = cb_clean($_GET['from'] ?? '');
        $to = cb_clean($_GET['to'] ?? '');

        $where = ['1=1'];
        $types = '';
        $params = [];

        if ($q !== '') {
            $where[] = "(c.full_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.conversion_context LIKE ? OR c.decision_type LIKE ? OR am.full_name LIKE ?)";
            $like = "%{$q}%";
            for ($i=0; $i<6; $i++) { $params[] = $like; $types .= 's'; }
        }
        if (in_array($type, ['Member','Visitor'], true)) {
            $where[] = 'c.person_type=?'; $params[] = $type; $types .= 's';
        }
        if (in_array($status, ['Pending Assignment','In Nurture','Paused','Completed','Transferred'], true)) {
            $where[] = 'c.status=?'; $params[] = $status; $types .= 's';
        }
        if ($assigned > 0) {
            $where[] = 'c.assigned_member_id=?'; $params[] = $assigned; $types .= 'i';
        }
        if ($from !== '' && cb_valid_date($from)) {
            $where[] = 'c.conversion_date>=?'; $params[] = $from; $types .= 's';
        }
        if ($to !== '' && cb_valid_date($to)) {
            $where[] = 'c.conversion_date<=?'; $params[] = $to; $types .= 's';
        }

        return cb_fetch_all(
            $conn,
            "SELECT c.*,
                    am.full_name assigned_member_name,
                    am.member_no assigned_member_no,
                    lm.full_name linked_member_name,
                    lm.member_no linked_member_no,
                    v.full_name linked_visitor_name,
                    (SELECT COUNT(*) FROM church_convert_followups f WHERE f.convert_id=c.id) followup_count,
                    (SELECT MAX(f.follow_up_date) FROM church_convert_followups f WHERE f.convert_id=c.id) last_followup_date
             FROM church_converts c
             LEFT JOIN members am ON am.id=c.assigned_member_id
             LEFT JOIN members lm ON lm.id=c.member_id
             LEFT JOIN church_visitors v ON v.id=c.visitor_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.conversion_date DESC,c.id DESC",
            $types,
            $params
        );
    }
}

if (!function_exists('cn_report_filter_text')) {
    function cn_report_filter_text(mysqli $conn): string
    {
        $parts = [];
        $q = cb_clean($_GET['q'] ?? '');
        $type = cb_clean($_GET['type'] ?? '');
        $status = cb_clean($_GET['status'] ?? '');
        $assigned = cb_int($_GET['assigned'] ?? 0);
        $from = cb_clean($_GET['from'] ?? '');
        $to = cb_clean($_GET['to'] ?? '');

        if ($q !== '') $parts[] = 'Search: ' . $q;
        if ($type !== '') $parts[] = 'Type: ' . $type;
        if ($status !== '') $parts[] = 'Status: ' . $status;
        if ($assigned > 0) {
            $row = cb_fetch_one($conn, "SELECT full_name FROM members WHERE id=? LIMIT 1", 'i', [$assigned]);
            if ($row) $parts[] = 'Assigned to: ' . trim((string)$row['full_name']);
        }
        if ($from !== '') $parts[] = 'From: ' . $from;
        if ($to !== '') $parts[] = 'To: ' . $to;

        return $parts ? implode(' | ', $parts) : 'All convert records';
    }
}
