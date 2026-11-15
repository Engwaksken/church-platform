<?php
declare(strict_types=1);

/**
 * Staff tools in the mobile app: recording collections (offertory, tithe, thanksgiving, pledge payments,
 * contributions, Good Samaritan) and managing pledges. Each tool needs the same permission as its page on
 * the website, assigned under Users > Permissions; admins have them all.
 */

require_once __DIR__ . '/../../includes/member-fields.php';

const MAPI_PAYMENT_METHODS = ['Cash', 'Mobile Money', 'Bank Transfer', 'Cheque', 'Other'];
const MAPI_OFFERTORY_SERVICES = ['Sunday Service', 'Wednesday Bible Study', 'Friday Prayer', 'Special Service', 'Revival', 'Other'];
const MAPI_PLEDGE_STATUSES = ['Pending', 'Partial', 'Fulfilled', 'Cancelled'];

/** The signed-in account, stopping with 403 unless it holds $permission. */
function mapi_staff_auth(mysqli $conn, string $permission, string $message = 'Your account is not assigned to this task.'): array
{
    $auth = mapi_auth($conn);
    if (!mapi_user_can($conn, $auth['user_id'], $permission)) mapi_fail(403, $message);
    return $auth;
}

function mapi_valid_date(string $date): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Active members for pickers, with their pledge numbers. */
function mapi_member_choices(mysqli $conn): array
{
    $pledgeNo = mapi_column($conn, 'members', 'pledge_no') ? 'pledge_no' : "'' AS pledge_no";
    $rows = mapi_rows($conn, "SELECT id, member_no, full_name, $pledgeNo FROM members WHERE COALESCE(status,'Active')='Active' ORDER BY full_name LIMIT 5000");
    foreach ($rows as &$row) $row['id'] = (int)$row['id'];
    unset($row);
    return $rows;
}

/** Service names members attend (for filters), without changing the members table. */
function mapi_service_choices(mysqli $conn): array
{
    $options = ['First Service', 'Second Service', 'Third Service', 'Youth Service', 'Sunday School'];
    if (mapi_column($conn, 'church_services', 'service_type')) {
        foreach (mapi_rows($conn, "SELECT DISTINCT TRIM(service_type) s FROM church_services WHERE TRIM(COALESCE(service_type,''))<>''") as $r) $options[] = (string)$r['s'];
    }
    if (mapi_column($conn, 'members', 'service_attended')) {
        foreach (mapi_rows($conn, "SELECT DISTINCT TRIM(service_attended) s FROM members WHERE TRIM(COALESCE(service_attended,''))<>''") as $r) $options[] = (string)$r['s'];
    }
    $seen = [];
    $out = [];
    foreach ($options as $o) {
        $k = strtolower($o);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $o;
    }
    return $out;
}

/* ------------------------------------------------------------------ home summary */

/** Figures for the home screen of an account with staff tools. */
function mapi_work_summary(mysqli $conn, array $auth): array
{
    $summary = [];
    $uid = $auth['user_id'];
    if (mapi_user_can($conn, $uid, 'pledges.view') && mapi_table($conn, 'pledges')) {
        $row = mapi_row($conn, "SELECT COALESCE(SUM(amount_pledged),0) pledged, COALESCE(SUM(amount_paid),0) paid,
            SUM(status IN ('Pending','Partial')) open_count FROM pledges WHERE status<>'Cancelled'");
        $month = 0.0;
        if (mapi_table($conn, 'pledge_payments')) {
            $start = date('Y-m-01');
            $end = date('Y-m-d', strtotime('first day of next month'));
            $month = (float)(mapi_row($conn, "SELECT COALESCE(SUM(pp.amount),0) t FROM pledge_payments pp JOIN pledges p ON p.id=pp.pledge_id
                WHERE pp.paid_on>=? AND pp.paid_on<? AND p.status<>'Cancelled'", 'ss', [$start, $end])['t'] ?? 0);
        }
        $summary['pledges'] = [
            'total_pledged' => (float)$row['pledged'],
            'total_paid' => (float)$row['paid'],
            'balance' => max(0, (float)$row['pledged'] - (float)$row['paid']),
            'open' => (int)$row['open_count'],
            'paid_this_month' => $month,
        ];
    }
    if (mapi_user_can($conn, $uid, 'offertory.view') && mapi_table($conn, 'offertory')) {
        $last = mapi_row($conn, 'SELECT service_date, service_type, total_amount FROM offertory ORDER BY service_date DESC, id DESC LIMIT 1');
        $month = mapi_row($conn, 'SELECT COALESCE(SUM(total_amount),0) t FROM offertory WHERE service_date>=?', 's', [date('Y-m-01')]);
        $summary['offertory'] = [
            'last_date' => $last['service_date'] ?? null,
            'last_service' => $last['service_type'] ?? null,
            'last_total' => (float)($last['total_amount'] ?? 0),
            'this_month' => (float)($month['t'] ?? 0),
        ];
    }
    return $summary;
}

/* ------------------------------------------------------------------ collections */

function mapi_action_role_tools(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    $tools = [];
    foreach (mapi_role_tool_permissions() as $tool => $permission) {
        $tools[$tool] = mapi_user_can($conn, $auth['user_id'], $permission);
    }
    $enabled = array_keys(array_filter($tools));
    $members = [];
    $projects = [];
    $pledges = [];
    $users = [];
    if (array_intersect($enabled, ['tithe', 'thanksgiving', 'contribution', 'good_samaritan'])) $members = mapi_member_choices($conn);
    if (in_array('contribution', $enabled, true) && mapi_table($conn, 'projects')) {
        $projects = mapi_rows($conn, "SELECT id,name FROM projects WHERE COALESCE(status,'Active')='Active' ORDER BY name");
        foreach ($projects as &$project) $project['id'] = (int)$project['id'];
        unset($project);
    }
    if (in_array('pledge_payment', $enabled, true) && mapi_table($conn, 'pledges')) $pledges = mapi_open_pledges($conn);
    if (in_array('offertory', $enabled, true)) {
        // Counted By / Witnessed By are picked from the website users, as on the offertory page.
        $users = mapi_rows($conn, "SELECT id, full_name FROM users WHERE TRIM(COALESCE(full_name,''))<>'' AND LOWER(TRIM(COALESCE(status,'Active')))='active' ORDER BY full_name");
        foreach ($users as &$user) $user['id'] = (int)$user['id'];
        unset($user);
    }
    mapi_ok([
        'items' => $enabled,
        'tools' => $tools,
        'members' => $members,
        'projects' => $projects,
        'pledges' => $pledges,
        'users' => $users,
        'services' => MAPI_OFFERTORY_SERVICES,
        'methods' => MAPI_PAYMENT_METHODS,
        'me' => mapi_account($conn, $auth)['full_name'],
    ]);
}

/** Pledges that can take a payment, with member and purpose for searching. */
function mapi_open_pledges(mysqli $conn): array
{
    $projectJoin = mapi_table($conn, 'projects') ? 'LEFT JOIN projects pr ON pr.id=p.project_id' : '';
    $projectName = $projectJoin ? 'pr.name' : 'NULL';
    [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
    $rows = mapi_rows($conn, "SELECT p.id, p.pledge_no, p.pledge_reason, $projectName AS project_name $typeSelect,
            p.amount_pledged, p.amount_paid, p.status, m.full_name, m.member_no
        FROM pledges p LEFT JOIN members m ON m.id=p.member_id $projectJoin $typeJoin
        WHERE p.status IN ('Pending','Partial') ORDER BY p.pledge_date DESC, p.id DESC LIMIT 3000");
    return array_map('mapi_pledge_row', $rows);
}

/** Types and computed fields for a pledge row. */
function mapi_pledge_row(array $p): array
{
    $p['purpose'] = pledge_purpose($p);
    $p['id'] = (int)$p['id'];
    $p['amount_pledged'] = (float)$p['amount_pledged'];
    $p['amount_paid'] = (float)$p['amount_paid'];
    $p['balance'] = max(0, $p['amount_pledged'] - $p['amount_paid']);
    foreach (['member_id', 'project_id', 'event_id', 'pledge_year', 'church_zone_id'] as $k) {
        if (array_key_exists($k, $p)) $p[$k] = $p[$k] === null ? null : (int)$p[$k];
    }
    return $p;
}

function mapi_active_member(mysqli $conn, int $memberId): bool
{
    return (bool)mapi_row($conn, "SELECT id FROM members WHERE id=? AND COALESCE(status,'Active')='Active'", 'i', [$memberId]);
}

/** Recomputes amount_paid and status from the payments, as the website does; a cancelled pledge stays cancelled. */
function mapi_recalc_pledge(mysqli $conn, int $pledgeId): void
{
    $p = mapi_row($conn, 'SELECT amount_pledged, status FROM pledges WHERE id=? LIMIT 1', 'i', [$pledgeId]);
    if (!$p) return;
    $paid = (float)(mapi_row($conn, 'SELECT COALESCE(SUM(amount),0) t FROM pledge_payments WHERE pledge_id=?', 'i', [$pledgeId])['t'] ?? 0);
    $status = $p['status'] === 'Cancelled' ? 'Cancelled' : ($paid >= (float)$p['amount_pledged'] ? 'Fulfilled' : ($paid > 0 ? 'Partial' : 'Pending'));
    $stmt = $conn->prepare('UPDATE pledges SET amount_paid=?, status=? WHERE id=?');
    $stmt->bind_param('dsi', $paid, $status, $pledgeId);
    $stmt->execute();
    $stmt->close();
}

function mapi_action_record_collection(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    $input = mapi_input();
    $type = mapi_str($input, 'type', 40);
    $permission = mapi_role_tool_permissions()[$type] ?? '';
    if ($permission === '' || !mapi_user_can($conn, $auth['user_id'], $permission)) mapi_fail(403, 'Your account is not assigned to record this collection.');
    $amount = (float)($input['amount'] ?? 0);
    $date = mapi_str($input, 'date', 10) ?: date('Y-m-d');
    $method = mapi_str($input, 'method', 40) ?: 'Cash';
    $notes = mapi_str($input, 'notes', 2000);
    if ($amount <= 0 || !mapi_valid_date($date)) mapi_fail(422, 'Enter a valid amount and date.');
    if (!in_array($method, MAPI_PAYMENT_METHODS, true)) mapi_fail(422, 'Select a valid payment method.');
    $receipt = 'RCOU-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $myName = mapi_account($conn, $auth)['full_name'];

    if ($type === 'offertory') {
        $envelope = max(0, (float)($input['envelope_total'] ?? 0));
        $loose = max(0, (float)($input['loose_total'] ?? 0));
        $extra = max(0, (float)($input['extra'] ?? 0));
        if ($envelope + $loose + $extra <= 0) mapi_fail(422, 'Enter an envelope, loose or additional amount.');
        $service = mapi_str($input, 'service_type', 80) ?: 'Sunday Service';
        $counted = mapi_str($input, 'counted_by', 120) ?: $myName;
        $witnessed = mapi_str($input, 'witnessed_by', 120);
        $total = $envelope + $loose + $extra;
        if (mapi_column($conn, 'offertory', 'recorded_by')) {
            // Who saved the entry, as on the website (database/offertory_recorded_by.sql).
            $st = $conn->prepare('INSERT INTO offertory (service_date,service_type,recorded_by,envelope_total,loose_total,total_amount,counted_by,witnessed_by,notes) VALUES (?,?,?,?,?,?,?,?,?)');
            $st->bind_param('ssidddsss', $date, $service, $auth['user_id'], $envelope, $loose, $total, $counted, $witnessed, $notes);
        } else {
            $st = $conn->prepare('INSERT INTO offertory (service_date,service_type,envelope_total,loose_total,total_amount,counted_by,witnessed_by,notes) VALUES (?,?,?,?,?,?,?,?)');
            $st->bind_param('ssdddsss', $date, $service, $envelope, $loose, $total, $counted, $witnessed, $notes);
        }
        $st->execute(); $id = $st->insert_id; $st->close();
        $receipt = '';
    } elseif ($type === 'tithe') {
        $memberId = (int)($input['member_id'] ?? 0); $month = (int)($input['period_month'] ?? (int)date('n')); $year = (int)($input['period_year'] ?? (int)date('Y'));
        if ($memberId > 0 && !mapi_active_member($conn, $memberId)) mapi_fail(422, 'Select an active member.');
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) mapi_fail(422, 'Select a valid tithe period.');
        $st = $conn->prepare('INSERT INTO tithe (receipt_no,member_id,amount,period_month,period_year,tithe_date,payment_method,notes) VALUES (?,NULLIF(?,0),?,?,?,?,?,?)');
        $st->bind_param('sidiisss', $receipt, $memberId, $amount, $month, $year, $date, $method, $notes); $st->execute(); $id = $st->insert_id; $st->close();
    } elseif ($type === 'thanksgiving') {
        $memberId = (int)($input['member_id'] ?? 0); $giver = mapi_str($input, 'giver_name', 120); $reason = mapi_str($input, 'reason', 250); $item = mapi_str($input, 'item_description', 250);
        if ($memberId > 0 && !mapi_active_member($conn, $memberId)) mapi_fail(422, 'Select an active member.');
        if ($memberId <= 0 && $giver === '') mapi_fail(422, 'Select a member or enter the giver\'s name.');
        if ($reason === '') mapi_fail(422, 'Enter the thanksgiving reason.');
        $st = $conn->prepare('INSERT INTO thanksgiving (receipt_no,member_id,giver_name,reason,amount,thanksgiving_date,payment_method,item_description,notes) VALUES (?,NULLIF(?,0),?,?,?,?,?,?,?)');
        $st->bind_param('sissdssss', $receipt, $memberId, $giver, $reason, $amount, $date, $method, $item, $notes); $st->execute(); $id = $st->insert_id; $st->close();
    } elseif ($type === 'good_samaritan') {
        $memberId = (int)($input['member_id'] ?? 0);
        if ($memberId > 0 && !mapi_active_member($conn, $memberId)) mapi_fail(422, 'Select an active member.');
        $st = $conn->prepare('INSERT INTO good_samaritan (receipt_no,member_id,amount,payment_method,given_on,notes,recorded_by) VALUES (?,NULLIF(?,0),?,?,?,?,?)');
        $st->bind_param('sidsssi', $receipt, $memberId, $amount, $method, $date, $notes, $auth['user_id']); $st->execute(); $id = $st->insert_id; $st->close();
    } elseif ($type === 'contribution') {
        $memberId = (int)($input['member_id'] ?? 0); $projectId = (int)($input['project_id'] ?? 0);
        if ($memberId <= 0 || $projectId <= 0) mapi_fail(422, 'Select a member and contribution project.');
        if (!mapi_active_member($conn, $memberId)) mapi_fail(422, 'Select an active member.');
        if (!mapi_row($conn, "SELECT id FROM projects WHERE id=? AND COALESCE(status,'Active')='Active'", 'i', [$projectId])) mapi_fail(422, 'Select an active project.');
        $st = $conn->prepare('INSERT INTO contributions (receipt_no,member_id,project_id,amount,payment_method,contributed_on,notes) VALUES (?,?,?,?,?,?,?)');
        $st->bind_param('siidsss', $receipt, $memberId, $projectId, $amount, $method, $date, $notes); $st->execute(); $id = $st->insert_id; $st->close();
    } else {
        if (!mapi_table($conn, 'pledge_payments')) mapi_fail(503, 'Pledge payments are not set up on the website.');
        $pledgeId = (int)($input['pledge_id'] ?? 0);
        $pledge = mapi_row($conn, "SELECT id,amount_pledged,amount_paid,status FROM pledges WHERE id=? AND status<>'Cancelled' LIMIT 1", 'i', [$pledgeId]);
        if (!$pledge) mapi_fail(422, 'Select an active pledge.');
        $balance = max(0, (float)$pledge['amount_pledged'] - (float)$pledge['amount_paid']);
        if ($amount > $balance) mapi_fail(422, 'Payment exceeds the remaining pledge balance of ' . number_format($balance) . '.');
        // Same receipt number shape as the website (e.g. RCP-20261031-213503-042).
        $receipt = 'RCP-' . date('Ymd-His') . '-' . random_int(100, 999);
        $st = $conn->prepare('INSERT INTO pledge_payments (pledge_id,amount,paid_on,method,receipt_no,notes) VALUES (?,?,?,?,?,?)');
        $st->bind_param('idssss', $pledgeId, $amount, $date, $method, $receipt, $notes); $st->execute(); $id = $st->insert_id; $st->close();
        mapi_recalc_pledge($conn, $pledgeId);
    }
    mapi_ok(['message' => 'Collection recorded.', 'id' => (int)$id, 'receipt_no' => $receipt]);
}

/* ------------------------------------------------------------------ pledges (staff) */

/**
 * Member zone and service filters from the query string, for queries that alias pledges p and members m.
 * Returns [extra SELECT, extra JOIN, WHERE conditions, types, params].
 */
function mapi_pledge_member_filters(mysqli $conn): array
{
    $select = '';
    $join = '';
    $cond = [];
    $types = '';
    $params = [];
    $zone = mapi_column($conn, 'members', 'church_zone_id') ? mapi_zone_source($conn) : null;
    if ($zone) {
        [$table, $col] = $zone;
        $select .= ", m.church_zone_id, z.`$col` AS zone_name";
        $join .= " LEFT JOIN `$table` z ON z.id=m.church_zone_id";
        $zoneId = (int)($_GET['zone_id'] ?? 0);
        if ($zoneId > 0) { $cond[] = 'm.church_zone_id=?'; $types .= 'i'; $params[] = $zoneId; }
    } else {
        $select .= ", NULL AS church_zone_id, '' AS zone_name";
    }
    if (mapi_column($conn, 'members', 'service_attended')) {
        $select .= ', m.service_attended';
        $service = trim((string)($_GET['service'] ?? ''));
        if ($service !== '') { $cond[] = 'TRIM(m.service_attended)=?'; $types .= 's'; $params[] = $service; }
    } else {
        $select .= ", '' AS service_attended";
    }
    return [$select, $join, $cond, $types, $params];
}

/** Choices for the pledge screens: filters, members, projects and events. */
function mapi_action_pledge_options(mysqli $conn): never
{
    mapi_staff_auth($conn, 'pledges.view', 'Your account is not assigned to manage pledges.');
    $projects = mapi_table($conn, 'projects') ? mapi_rows($conn, "SELECT id, name FROM projects WHERE COALESCE(status,'Active')='Active' ORDER BY name") : [];
    $events = mapi_table($conn, 'events') ? mapi_rows($conn, 'SELECT id, title, event_date FROM events ORDER BY event_date DESC LIMIT 300') : [];
    foreach ($projects as &$p) $p['id'] = (int)$p['id'];
    unset($p);
    foreach ($events as &$e) $e['id'] = (int)$e['id'];
    unset($e);
    $yearColumn = pledge_types_ready($conn) ? 'COALESCE(pledge_year,YEAR(pledge_date))' : 'YEAR(pledge_date)';
    $years = array_map(fn($r) => (int)$r['y'], mapi_rows($conn, "SELECT DISTINCT $yearColumn y FROM pledges WHERE pledge_date IS NOT NULL ORDER BY y DESC"));
    if (!in_array((int)date('Y'), $years, true)) array_unshift($years, (int)date('Y'));
    mapi_ok([
        'types' => pledge_types(),
        'types_ready' => pledge_types_ready($conn),
        'statuses' => MAPI_PLEDGE_STATUSES,
        'methods' => MAPI_PAYMENT_METHODS,
        'years' => array_values(array_filter($years)),
        'current_year' => (int)date('Y'),
        'zones' => member_fields_zone_options($conn),
        'services' => mapi_column($conn, 'members', 'service_attended') ? mapi_service_choices($conn) : [],
        'members' => mapi_member_choices($conn),
        'projects' => $projects,
        'events' => $events,
    ]);
}

/**
 * Pledges with the website's filters (search, status, type, year, zone, service, overdue), a page of
 * results and totals that follow the filters. A search that looks like a pledge number (12, P-012)
 * matches that pledge number exactly instead of every number containing the digits.
 */
function mapi_action_staff_pledges(mysqli $conn): never
{
    mapi_staff_auth($conn, 'pledges.view', 'Your account is not assigned to manage pledges.');
    if (!mapi_table($conn, 'pledges')) mapi_ok(['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'stats' => []]);
    [$memberSelect, $memberJoin, $cond, $types, $params] = mapi_pledge_member_filters($conn);
    $memberCond = $cond;
    $memberTypes = $types;
    $memberParams = $params;

    $status = trim((string)($_GET['status'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));
    $year = (int)($_GET['year'] ?? 0);
    $search = trim((string)($_GET['search'] ?? ''));
    if ($status !== '' && in_array($status, MAPI_PLEDGE_STATUSES, true)) { $cond[] = 'p.status=?'; $types .= 's'; $params[] = $status; }
    if ((int)($_GET['overdue'] ?? 0) === 1) $cond[] = "p.status IN('Pending','Partial') AND p.due_date IS NOT NULL AND p.due_date<CURDATE()";
    if ($type !== '' && in_array($type, pledge_types(), true)) {
        if (pledge_types_ready($conn)) { $cond[] = 'p.pledge_type=?'; $types .= 's'; $params[] = $type; }
        else $cond[] = $type === 'Annual' ? "p.pledge_reason LIKE 'Annual Pledge %'" : '1=0';
    }
    if ($year >= 2000 && $year <= 2100) {
        $cond[] = pledge_types_ready($conn) ? '(p.pledge_year=? OR (p.pledge_year IS NULL AND YEAR(p.pledge_date)=?))' : 'YEAR(p.pledge_date)=?';
        $types .= pledge_types_ready($conn) ? 'ii' : 'i';
        array_push($params, ...(pledge_types_ready($conn) ? [$year, $year] : [$year]));
    }
    if ($search !== '') {
        $ids = member_fields_pledge_search_ids($conn, $search);
        if ($ids) {
            $cond[] = 'p.member_id IN (' . implode(',', array_map('intval', $ids)) . ')';
        } else {
            $like = '%' . $search . '%';
            $cond[] = '(m.full_name LIKE ? OR m.member_no LIKE ? OR p.pledge_no LIKE ? OR p.pledge_reason LIKE ?)';
            $types .= 'ssss';
            array_push($params, $like, $like, $like, $like);
        }
    }
    $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
    $from = "pledges p LEFT JOIN members m ON m.id=p.member_id $memberJoin";
    $projectJoin = mapi_table($conn, 'projects') ? 'LEFT JOIN projects pr ON pr.id=p.project_id' : '';
    $projectName = $projectJoin ? 'pr.name' : 'NULL';
    [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);

    $total = (int)(mapi_row($conn, "SELECT COUNT(*) c FROM $from $where", $types, $params)['c'] ?? 0);
    $perPage = max(5, min(100, (int)($_GET['per_page'] ?? 30)));
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;
    $phone = mapi_column($conn, 'members', 'phone') ? 'm.phone' : 'NULL AS phone';
    $items = mapi_rows($conn, "SELECT p.id, p.pledge_no, p.member_id, p.project_id, p.pledge_reason, p.amount_pledged, p.amount_paid,
            p.pledge_date, p.due_date, p.status, p.notes, m.full_name, m.member_no, $phone, $projectName AS project_name $typeSelect $memberSelect
        FROM $from $projectJoin $typeJoin $where
        ORDER BY p.pledge_date DESC, p.id DESC LIMIT $perPage OFFSET $offset", $types, $params);

    // Totals follow the filters; cancelled pledges are left out unless the user filtered to them.
    $statsWhere = $where . ($status !== 'Cancelled' ? ($where === '' ? 'WHERE ' : ' AND ') . "p.status<>'Cancelled'" : '');
    $stats = mapi_row($conn, "SELECT COUNT(*) count, COALESCE(SUM(p.amount_pledged),0) total, COALESCE(SUM(p.amount_paid),0) paid,
            COALESCE(SUM(p.status='Pending'),0) pending, COALESCE(SUM(p.status='Partial'),0) partial, COALESCE(SUM(p.status='Fulfilled'),0) fulfilled,
            COALESCE(SUM(p.status IN('Pending','Partial') AND p.due_date IS NOT NULL AND p.due_date<CURDATE()),0) overdue,
            COUNT(DISTINCT p.member_id) members
        FROM $from $statsWhere", $types, $params) ?? [];
    $yearColumn = pledge_types_ready($conn) ? 'COALESCE(p.pledge_year,YEAR(p.pledge_date))' : 'YEAR(p.pledge_date)';
    $memberAnd = $memberCond ? ' AND ' . implode(' AND ', $memberCond) : '';
    $thisYear = (int)date('Y');
    $lastYear = $thisYear - 1;
    $yearStats = mapi_row($conn, "SELECT COALESCE(SUM(CASE WHEN $yearColumn=$thisYear THEN p.amount_pledged ELSE 0 END),0) this_year,
            COALESCE(SUM(CASE WHEN $yearColumn=$lastYear THEN p.amount_pledged ELSE 0 END),0) last_year
        FROM $from WHERE p.status<>'Cancelled'$memberAnd", $memberTypes, $memberParams) ?? [];
    $paidThisMonth = 0.0;
    if (mapi_table($conn, 'pledge_payments')) {
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-d', strtotime('first day of next month'));
        $paidThisMonth = (float)(mapi_row($conn, "SELECT COALESCE(SUM(pp.amount),0) t FROM pledge_payments pp JOIN pledges p ON p.id=pp.pledge_id
                LEFT JOIN members m ON m.id=p.member_id $memberJoin
                WHERE pp.paid_on>=? AND pp.paid_on<? AND p.status<>'Cancelled'$memberAnd", 'ss' . $memberTypes, array_merge([$monthStart, $monthEnd], $memberParams))['t'] ?? 0);
    }
    $out = [];
    foreach (['count', 'pending', 'partial', 'fulfilled', 'overdue', 'members'] as $k) $out[$k] = (int)($stats[$k] ?? 0);
    $out['total'] = (float)($stats['total'] ?? 0);
    $out['paid'] = (float)($stats['paid'] ?? 0);
    $out['balance'] = max(0, $out['total'] - $out['paid']);
    $out['open'] = $out['pending'] + $out['partial'];
    $out['this_year'] = (float)($yearStats['this_year'] ?? 0);
    $out['last_year'] = (float)($yearStats['last_year'] ?? 0);
    $out['paid_this_month'] = $paidThisMonth;
    mapi_ok([
        'items' => array_map('mapi_pledge_row', $items),
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
        'stats' => $out,
    ]);
}

function mapi_action_pledge_detail(mysqli $conn): never
{
    mapi_staff_auth($conn, 'pledges.view', 'Your account is not assigned to manage pledges.');
    $id = (int)($_GET['id'] ?? 0);
    $projectJoin = mapi_table($conn, 'projects') ? 'LEFT JOIN projects pr ON pr.id=p.project_id' : '';
    $projectName = $projectJoin ? 'pr.name' : 'NULL';
    [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
    [$memberSelect, $memberJoin] = mapi_pledge_member_filters($conn);
    $phone = mapi_column($conn, 'members', 'phone') ? 'm.phone' : 'NULL AS phone';
    $row = mapi_row($conn, "SELECT p.*, m.full_name, m.member_no, $phone, $projectName AS project_name $typeSelect $memberSelect
        FROM pledges p LEFT JOIN members m ON m.id=p.member_id $memberJoin $projectJoin $typeJoin WHERE p.id=? LIMIT 1", 'i', [$id]);
    if (!$row) mapi_fail(404, 'Pledge not found.');
    $payments = mapi_table($conn, 'pledge_payments')
        ? mapi_rows($conn, 'SELECT id, amount, paid_on, method, receipt_no, notes FROM pledge_payments WHERE pledge_id=? ORDER BY paid_on DESC, id DESC', 'i', [$id])
        : [];
    foreach ($payments as &$pay) { $pay['id'] = (int)$pay['id']; $pay['amount'] = (float)$pay['amount']; }
    unset($pay);
    mapi_ok(['item' => mapi_pledge_row($row), 'payments' => $payments]);
}

/** Records a new pledge with the website's rules; the pledge number comes from the member record. */
function mapi_action_save_pledge(mysqli $conn): never
{
    mapi_require_method('POST');
    mapi_staff_auth($conn, 'pledges.view', 'Your account is not assigned to manage pledges.');
    $input = mapi_input();
    $memberId = (int)($input['member_id'] ?? 0);
    $projectId = (int)($input['project_id'] ?? 0);
    $reason = mapi_str($input, 'pledge_reason', 255);
    $amount = (float)($input['amount_pledged'] ?? 0);
    $pledgeDate = mapi_str($input, 'pledge_date', 10) ?: date('Y-m-d');
    $due = mapi_str($input, 'due_date', 10);
    $notes = mapi_str($input, 'notes', 2000);
    if ($memberId <= 0 || !mapi_active_member($conn, $memberId)) mapi_fail(422, 'Please select a member.');
    if ($amount <= 0) mapi_fail(422, 'Amount pledged must be greater than zero.');
    if (!mapi_valid_date($pledgeDate)) mapi_fail(422, 'Enter a valid pledge date.');
    if ($due !== '' && !mapi_valid_date($due)) mapi_fail(422, 'Enter a valid due date.');

    $pledgeNo = mapi_column($conn, 'members', 'pledge_no')
        ? trim((string)(mapi_row($conn, 'SELECT pledge_no FROM members WHERE id=? LIMIT 1', 'i', [$memberId])['pledge_no'] ?? ''))
        : '';
    if ($pledgeNo === '') mapi_fail(422, 'This member has no pledge number. Set the Pledge No on the member record on the website first.');

    $cols = ['pledge_no' => ['s', $pledgeNo], 'member_id' => ['i', $memberId], 'pledge_reason' => ['s', $reason], 'amount_pledged' => ['d', $amount],
        'amount_paid' => ['d', 0.0], 'pledge_date' => ['s', $pledgeDate], 'due_date' => ['s', $due !== '' ? $due : null], 'status' => ['s', 'Pending'], 'notes' => ['s', $notes]];
    if (pledge_types_ready($conn)) {
        $type = mapi_str($input, 'pledge_type', 40);
        $eventId = (int)($input['event_id'] ?? 0);
        $year = (int)($input['pledge_year'] ?? 0);
        if (!in_array($type, pledge_types(), true)) mapi_fail(422, 'Please choose what the pledge is for.');
        if ($type === 'Project' && $projectId <= 0) mapi_fail(422, 'Please select the project.');
        if ($type === 'Event' && $eventId <= 0) mapi_fail(422, 'Please select the event.');
        if ($type === 'Annual' && ($year < 2000 || $year > 2100)) mapi_fail(422, 'Please enter the year of the annual pledge.');
        if ($type === 'Harvest Items' && $reason === '') mapi_fail(422, 'Please describe the harvest items pledged.');
        if ($type === 'Other' && $reason === '') mapi_fail(422, 'Please specify what the pledge is for.');
        $cols['pledge_type'] = ['s', $type];
        $cols['pledge_year'] = ['i', $type === 'Annual' ? $year : null];
        $cols['event_id'] = ['i', $type === 'Event' ? $eventId : null];
        if ($type !== 'Project') $projectId = 0;
    }
    $cols['project_id'] = ['i', $projectId > 0 ? $projectId : null];
    $names = array_keys($cols);
    $stmt = $conn->prepare('INSERT INTO pledges (`' . implode('`,`', $names) . '`) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')');
    $types = implode('', array_column($cols, 0));
    $values = array_column($cols, 1);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    mapi_ok(['message' => 'Pledge recorded successfully.', 'id' => $id, 'pledge_no' => $pledgeNo]);
}

/** Pledge payments received in a month (YYYY-MM, default this month) or a date range, with zone and service filters. */
function mapi_action_pledge_payments(mysqli $conn): never
{
    mapi_staff_auth($conn, 'pledges.view', 'Your account is not assigned to manage pledges.');
    $month = trim((string)($_GET['month'] ?? ''));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
    if (!mapi_table($conn, 'pledge_payments')) mapi_ok(['items' => [], 'month' => $month, 'total' => 0]);
    $start = $month . '-01';
    $end = date('Y-m-d', strtotime($start . ' +1 month'));
    $from = trim((string)($_GET['date_from'] ?? ''));
    $to = trim((string)($_GET['date_to'] ?? ''));
    if (($from !== '' && mapi_valid_date($from)) || ($to !== '' && mapi_valid_date($to))) {
        $start = mapi_valid_date($from) ? $from : '1900-01-01';
        $end = date('Y-m-d', strtotime((mapi_valid_date($to) ? $to : date('Y-m-d')) . ' +1 day'));
    }
    [$memberSelect, $memberJoin, $cond, $types, $params] = mapi_pledge_member_filters($conn);
    $memberAnd = $cond ? ' AND ' . implode(' AND ', $cond) : '';
    $projectJoin = mapi_table($conn, 'projects') ? 'LEFT JOIN projects pr ON pr.id=p.project_id' : '';
    $projectName = $projectJoin ? 'pr.name' : 'NULL';
    [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
    $items = mapi_rows($conn, "SELECT pp.id, pp.pledge_id, pp.amount, pp.paid_on, pp.method, pp.receipt_no,
            p.pledge_no, p.pledge_reason, p.status, p.amount_pledged, p.amount_paid, m.full_name, m.member_no, $projectName AS project_name $typeSelect $memberSelect
        FROM pledge_payments pp JOIN pledges p ON p.id=pp.pledge_id
        LEFT JOIN members m ON m.id=p.member_id $memberJoin $projectJoin $typeJoin
        WHERE pp.paid_on>=? AND pp.paid_on<? AND p.status<>'Cancelled'$memberAnd
        ORDER BY pp.paid_on DESC, pp.id DESC LIMIT 2000", 'ss' . $types, array_merge([$start, $end], $params));
    $total = 0.0;
    foreach ($items as &$r) {
        $r['purpose'] = pledge_purpose($r);
        $r['id'] = (int)$r['id'];
        $r['pledge_id'] = (int)$r['pledge_id'];
        $r['amount'] = (float)$r['amount'];
        $total += $r['amount'];
    }
    unset($r);
    mapi_ok(['items' => $items, 'month' => $month, 'total' => $total]);
}
