<?php
declare(strict_types=1);

/**
 * Pledge types and the purpose label shown for a pledge (list, card, receipts, emails, member app).
 * database/pledge_types.sql adds pledges.pledge_type, pledge_year and event_id; until it is run,
 * pledges are labelled as before (project, else reason, else "General Pledge").
 */

function pledge_types(): array
{
    return ['Annual', 'Project', 'Harvest Items', 'Event', 'Other'];
}

/** True once database/pledge_types.sql has been run. */
function pledge_types_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $res = $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pledges' AND COLUMN_NAME IN ('pledge_type','pledge_year','event_id')");
        $ready = $res && (int)$res->fetch_row()[0] === 3;
    }
    return $ready;
}

/**
 * Extra SELECT columns and JOIN that pledge_purpose() needs, for a query whose pledges alias is $p:
 *   [$select, $join] = pledge_purpose_sql($conn); "SELECT p.* $select ... FROM pledges p ... $join"
 * Both are empty until the migration has been run.
 */
function pledge_purpose_sql(mysqli $conn, string $p = 'p'): array
{
    if (!pledge_types_ready($conn)) return ['', ''];
    $res = $conn->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events'");
    $hasEvents = $res && (int)$res->fetch_row()[0] > 0;
    return [
        ", $p.pledge_type, $p.pledge_year, $p.event_id, " . ($hasEvents ? 'pev.title' : 'NULL') . ' AS event_title',
        $hasEvents ? " LEFT JOIN events pev ON pev.id = $p.event_id" : '',
    ];
}

/** What the pledge is for, e.g. "Annual Pledge 2026", "Harvest: 2 goats", a project or event name. */
function pledge_purpose(array $row): string
{
    $reason = trim((string)($row['pledge_reason'] ?? ''));
    $project = trim((string)($row['project_name'] ?? ''));
    $event = trim((string)($row['event_title'] ?? ''));
    switch (trim((string)($row['pledge_type'] ?? ''))) {
        case 'Annual':
            $year = (int)($row['pledge_year'] ?? 0);
            return 'Annual Pledge' . ($year > 0 ? ' ' . $year : '');
        case 'Project':
            return $project !== '' ? $project : ($reason !== '' ? $reason : 'Project');
        case 'Harvest Items':
            return 'Harvest: ' . ($reason !== '' ? $reason : 'items');
        case 'Event':
            return $event !== '' ? $event : ($reason !== '' ? $reason : 'Event');
        default:
            // "Other" (specified in the reason) and pledges recorded before pledge types existed.
            return $project !== '' ? $project : ($reason !== '' ? $reason : 'General Pledge');
    }
}
