<?php
declare(strict_types=1);

/*
 * Shared support for the member pledge number and service attended fields.
 * Used by the admin members module, public self-registration and the member portal.
 */

function member_fields_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $column); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

/** Submitted full name, falling back to first_name/last_name (e.g. older CSV files). */
function member_full_name(array $data): string
{
    $full = trim((string)($data['full_name'] ?? ''));
    if ($full === '') $full = trim((string)($data['first_name'] ?? '') . ' ' . (string)($data['last_name'] ?? ''));
    return (string)preg_replace('/\s+/', ' ', $full);
}

/**
 * Add members.pledge_no / members.service_attended when missing.
 * Call this before opening a transaction: ALTER TABLE implicitly commits.
 */
function member_fields_ensure(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $ok = true;
    $defs = [
        'pledge_no'        => "ALTER TABLE members ADD COLUMN pledge_no VARCHAR(50) NULL AFTER member_no",
        'service_attended' => "ALTER TABLE members ADD COLUMN service_attended VARCHAR(120) NULL"
            . (member_fields_column_exists($conn, 'members', 'church_zone_id') ? ' AFTER church_zone_id' : ''),
    ];
    foreach ($defs as $col => $sql) {
        if (member_fields_column_exists($conn, 'members', $col)) continue;
        try { if (!$conn->query($sql)) $ok = false; }
        catch (Throwable $e) { $ok = false; error_log("Could not add members.$col: " . $e->getMessage()); }
    }
    return $ready = $ok;
}

/**
 * Service choices: common defaults, service types recorded under Church Services,
 * and values already assigned to members.
 */
function member_fields_service_options(mysqli $conn): array
{
    $opts = ['First Service', 'Second Service', 'Third Service', 'Youth Service', 'Sunday School'];
    $queries = [];
    if (member_fields_column_exists($conn, 'church_services', 'service_type'))
        $queries[] = "SELECT DISTINCT TRIM(service_type) s FROM church_services WHERE TRIM(COALESCE(service_type,''))<>''";
    if (member_fields_ensure($conn))
        $queries[] = "SELECT DISTINCT TRIM(service_attended) s FROM members WHERE TRIM(COALESCE(service_attended,''))<>''";
    foreach ($queries as $sql) {
        $r = $conn->query($sql);
        if ($r) while ($x = $r->fetch_assoc()) $opts[] = (string)$x['s'];
    }
    $seen = []; $out = [];
    foreach ($opts as $o) {
        $k = strtolower($o);
        if (isset($seen[$k])) continue;
        $seen[$k] = true; $out[] = $o;
    }
    return $out;
}

function member_fields_pledge_taken(mysqli $conn, string $pledgeNo, int $excludeMemberId = 0): bool
{
    if ($pledgeNo === '') return false;
    $stmt = $conn->prepare("SELECT id FROM members WHERE pledge_no=? AND id<>? LIMIT 1");
    $stmt->bind_param('si', $pledgeNo, $excludeMemberId); $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc(); $stmt->close();
    return $found;
}

/**
 * Church zone choices as [['id'=>int,'name'=>string], ...], empty when members have no zone column
 * or no zone table exists. Used by filters (e.g. pledges by zone).
 */
function member_fields_zone_options(mysqli $conn): array
{
    if (!member_fields_column_exists($conn, 'members', 'church_zone_id')) return [];
    foreach (['church_zones', 'zones'] as $table) {
        $r = $conn->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'");
        if (!$r || (int)$r->fetch_row()[0] === 0) continue;
        foreach (['zone_name', 'name', 'title'] as $col) {
            if (!member_fields_column_exists($conn, $table, $col)) continue;
            $out = [];
            $res = $conn->query("SELECT id, `$col` AS name FROM `$table` ORDER BY `$col`");
            if ($res) while ($z = $res->fetch_assoc()) $out[] = ['id' => (int)$z['id'], 'name' => (string)$z['name']];
            return $out;
        }
    }
    return [];
}

/** Pledge number key for comparing: lower-case letters/digits only, leading zeros dropped ("P-007" -> "p7"). */
function member_fields_pledge_key(string $v): string
{
    $k = preg_replace('/[^a-z0-9]/', '', strtolower($v)) ?? '';
    return preg_replace_callback('/\d+/', fn($m) => ltrim($m[0], '0') === '' ? '0' : ltrim($m[0], '0'), $k) ?? '';
}

/**
 * When a search looks like a pledge number (e.g. "12", "P-012", "p 12"), the ids of members whose
 * pledge number is exactly that number, so the list shows only them instead of every member whose
 * pledge no, phone or member no merely contains the digits. Null when it is not a pledge number
 * search or nobody has that pledge number (the normal text search is used then).
 */
function member_fields_pledge_search_ids(mysqli $conn, string $search): ?array
{
    $search = trim($search);
    if (!preg_match('/^[a-z]{0,4}[-\/ ]?\d+$/i', $search) || !member_fields_column_exists($conn, 'members', 'pledge_no')) return null;
    $key = member_fields_pledge_key($search);
    $digits = preg_replace('/\D/', '', $search);
    $digitKey = member_fields_pledge_key($digits);
    $stmt = $conn->prepare("SELECT id, pledge_no FROM members WHERE pledge_no LIKE ?");
    $like = '%' . ltrim($digits, '0') . '%';
    $stmt->bind_param('s', $like); $stmt->execute();
    $res = $stmt->get_result();
    $exact = []; $byNumber = [];
    while ($r = $res->fetch_assoc()) {
        $pn = (string)$r['pledge_no'];
        if (member_fields_pledge_key($pn) === $key) $exact[] = (int)$r['id'];
        elseif (member_fields_pledge_key(preg_replace('/\D/', '', $pn) ?? '') === $digitKey) $byNumber[] = (int)$r['id'];
    }
    $stmt->close();
    // "12" also finds "P-012"; "P-12" only finds pledge numbers with the same prefix unless none exist.
    $ids = $exact ?: $byNumber;
    return $ids ?: null;
}
