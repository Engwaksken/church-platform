<?php
declare(strict_types=1);

/** Service choices shared by offertory and Good Samaritan collections. */

function service_type_options(): array
{
    return ['Sunday Service', 'Wednesday Bible Study', 'Friday Prayer', 'Special Service', 'Revival', 'Other'];
}

/** Which service of the day (database/service_session.sql adds the columns). */
function service_session_options(): array
{
    return ['First Service', 'Second Service', 'Third Service', 'Fourth Service', 'Evening Service', 'Single / Combined Service'];
}

/** True once database/service_session.sql has added $column, so pages keep working before it is run. */
function service_column_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = "$table.$column";
    if (!array_key_exists($key, $cache)) {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $cache[$key] = (int)$stmt->get_result()->fetch_row()[0] > 0;
        $stmt->close();
    }
    return $cache[$key];
}

/** "Sunday Service - First Service", or whichever parts are set (plain hyphen: some receipt fonts lack "·"). */
function service_label(?string $type, ?string $session): string
{
    return implode(' - ', array_filter([trim((string)$type), trim((string)$session)], 'strlen'));
}
