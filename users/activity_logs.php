<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('activity_h')) {
    function activity_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('activity_bind_params')) {
    function activity_bind_params(
        mysqli_stmt $stmt,
        string $types,
        array &$params
    ): void {
        if ($types === '' || $params === []) {
            return;
        }

        $bind = [$types];

        foreach ($params as &$value) {
            $bind[] = &$value;
        }

        unset($value);

        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
}

if (!function_exists('activity_query_scalar')) {
    function activity_query_scalar(mysqli $conn, string $sql): int
    {
        $result = $conn->query($sql);

        if (!$result) {
            return 0;
        }

        $row = $result->fetch_row();
        $result->free();

        return (int)($row[0] ?? 0);
    }
}

if (!function_exists('activity_build_url')) {
    function activity_build_url(array $query, int $page): string
    {
        $query['page'] = $page;

        return '?' . http_build_query($query);
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS activity_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NULL,
        target_user_id INT NULL,
        action VARCHAR(100) NOT NULL,
        description VARCHAR(500) NOT NULL,
        details JSON NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_activity_user_id (user_id),
        KEY idx_activity_target_user_id (target_user_id),
        KEY idx_activity_action (action),
        KEY idx_activity_created_at (created_at)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci"
);

$page = max(1, (int)($_GET['page'] ?? 1));

$perPage = (int)($_GET['per_page'] ?? 25);

if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 25;
}

$search = trim((string)($_GET['search'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$where = [];
$types = '';
$params = [];

if ($search !== '') {
    $where[] = '(
        l.description LIKE ?
        OR l.action LIKE ?
        OR actor.full_name LIKE ?
        OR actor.username LIKE ?
        OR target.full_name LIKE ?
        OR target.username LIKE ?
        OR l.ip_address LIKE ?
    )';

    $like = '%' . $search . '%';

    array_push(
        $params,
        $like,
        $like,
        $like,
        $like,
        $like,
        $like,
        $like
    );

    $types .= 'sssssss';
}

if ($action !== '') {
    $where[] = 'l.action = ?';
    $params[] = $action;
    $types .= 's';
}

if ($dateFrom !== '') {
    $where[] = 'DATE(l.created_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = 'DATE(l.created_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = $where !== []
    ? ' WHERE ' . implode(' AND ', $where)
    : '';

$countSql = "
    SELECT COUNT(*) AS total
    FROM activity_logs l
    LEFT JOIN users actor
        ON actor.id = l.user_id
    LEFT JOIN users target
        ON target.id = l.target_user_id
    {$whereSql}
";

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    http_response_code(500);
    exit('Unable to prepare the activity count query.');
}

$countParams = $params;
activity_bind_params($countStmt, $types, $countParams);

$countStmt->execute();

$countRow = $countStmt->get_result()->fetch_assoc();
$total = (int)($countRow['total'] ?? 0);

$countStmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = "
    SELECT
        l.id,
        l.user_id,
        l.target_user_id,
        l.action,
        l.description,
        l.details,
        l.ip_address,
        l.user_agent,
        l.created_at,
        actor.full_name AS actor_name,
        actor.username AS actor_username,
        target.full_name AS target_name,
        target.username AS target_username
    FROM activity_logs l
    LEFT JOIN users actor
        ON actor.id = l.user_id
    LEFT JOIN users target
        ON target.id = l.target_user_id
    {$whereSql}
    ORDER BY l.id DESC
    LIMIT ? OFFSET ?
";

$listStmt = $conn->prepare($listSql);

if (!$listStmt) {
    http_response_code(500);
    exit('Unable to prepare the activity list query.');
}

$listParams = array_merge($params, [$perPage, $offset]);
$listTypes = $types . 'ii';

activity_bind_params($listStmt, $listTypes, $listParams);

$listStmt->execute();

$logsResult = $listStmt->get_result();
$logs = $logsResult
    ? $logsResult->fetch_all(MYSQLI_ASSOC)
    : [];

$listStmt->close();

$actions = [];

$actionResult = $conn->query(
    "SELECT DISTINCT action
     FROM activity_logs
     WHERE action IS NOT NULL
       AND action <> ''
     ORDER BY action ASC"
);

while ($actionResult && $row = $actionResult->fetch_assoc()) {
    $actions[] = (string)$row['action'];
}

if ($actionResult) {
    $actionResult->free();
}

$statsResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today,
        COALESCE(SUM(action = 'login_success'), 0) AS successful_logins,
        COUNT(DISTINCT CASE WHEN action = 'login_success' THEN user_id END) AS login_users
     FROM activity_logs"
);

$stats = $statsResult
    ? ($statsResult->fetch_assoc() ?: [])
    : [];

if ($statsResult) {
    $statsResult->free();
}

$pageTitle = 'Activity Logs';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>



<div class="main-content">
    <?php require_once __DIR__ . '/../includes/topbar.php'; ?>

    <div class="content-area activity-logs-page">

        <?= function_exists('showFlash') ? showFlash() : '' ?>

        <div class="module-page-header">
            <div class="module-title">
                <h2>
                    <i class="fas fa-clock-rotate-left"></i>
                    Activity Logs
                </h2>

                <p>
                    Review successful logins, authentication events, user-management actions and administrative activity.
                </p>
            </div>

            <div class="module-actions">
                <a
                    href="<?= activity_h(BASE_URL . 'users/') ?>"
                    class="btn btn-outline"
                >
                    <i class="fas fa-users"></i>
                    Manage Users
                </a>

                <a
                    href="<?= activity_h(BASE_URL . 'users/permissions.php') ?>"
                    class="btn btn-primary"
                >
                    <i class="fas fa-key"></i>
                    User Permissions
                </a>
            </div>
        </div>

        <div class="stats-grid module-stats-grid activity-stats-grid">

            <div class="stat-card activity-stat-card">
                <div class="stat-icon">
                    <i class="fas fa-list-check"></i>
                </div>

                <div class="stat-body">
                    <div class="stat-number">
                        <?= number_format((int)($stats['total'] ?? 0)) ?>
                    </div>

                    <div class="stat-label">Total Activities</div>
                </div>
            </div>

            <div class="stat-card activity-stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-day"></i>
                </div>

                <div class="stat-body">
                    <div class="stat-number">
                        <?= number_format((int)($stats['today'] ?? 0)) ?>
                    </div>

                    <div class="stat-label">Activities Today</div>
                </div>
            </div>

            <div class="stat-card activity-stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-shield"></i>
                </div>

                <div class="stat-body">
                    <div class="stat-number">
                        <?= number_format((int)($stats['successful_logins'] ?? 0)) ?>
                    </div>

                    <div class="stat-label">Successful Logins</div>
                </div>
            </div>

            <div class="stat-card activity-stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-plus"></i>
                </div>

                <div class="stat-body">
                    <div class="stat-number">
                        <?= number_format((int)($stats['login_users'] ?? 0)) ?>
                    </div>

                    <div class="stat-label">Users Logged In</div>
                </div>
            </div>

        </div>

                <form
                    method="get"
                    class="page-toolbar"
                    id="activityFilterForm"
                >
                    <div class="page-toolbar-search">
                        <i class="fas fa-search"></i>

                        <input
                            type="search"
                            name="search"
                            value="<?= activity_h($search) ?>"
                            placeholder="Search activity, actor, target or IP..."
                            autocomplete="off"
                        >
                    </div>

                    <div class="page-toolbar-filters">
                    <select
                        name="action"
                        class="form-control"
                        aria-label="Filter by action"
                    >
                        <option value="">All Actions</option>

                        <?php foreach ($actions as $availableAction): ?>
                            <option
                                value="<?= activity_h($availableAction) ?>"
                                <?= $action === $availableAction ? 'selected' : '' ?>
                            >
                                <?= activity_h(
                                    ucwords(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $availableAction
                                        )
                                    )
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input
                        type="date"
                        name="date_from"
                        class="form-control"
                        value="<?= activity_h($dateFrom) ?>"
                        aria-label="Date from"
                    >

                    <input
                        type="date"
                        name="date_to"
                        class="form-control"
                        value="<?= activity_h($dateTo) ?>"
                        aria-label="Date to"
                    >

                    <select
                        name="per_page"
                        class="form-control"
                        aria-label="Records per page"
                    >
                        <?php foreach ([10, 25, 50, 100] as $size): ?>
                            <option
                                value="<?= $size ?>"
                                <?= $perPage === $size ? 'selected' : '' ?>
                            >
                                <?= $size ?> per page
                            </option>
                        <?php endforeach; ?>
                    </select>
                    </div>

                    <div class="page-toolbar-actions">
                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            <i class="fas fa-filter"></i>
                            Filter
                        </button>

                        <a
                            href="activity_logs.php"
                            class="btn btn-outline-primary page-toolbar-refresh"
                        >
                            <i class="fas fa-rotate-right"></i>
                            Refresh
                        </a>
                    </div>
                </form>
        <div class="card module-card">

            <div class="module-card-header">
                <div>
                    <strong>
                        <i class="fas fa-shield-halved"></i>
                        Audit Trail
                    </strong>

                    <p class="activity-card-description">
                        Filter activities by keyword, action, or date range.
                    </p>
                </div>

                <span class="module-count">
                    <?= number_format($total) ?> records
                </span>
            </div>

            <div class="card-body">


                <div class="module-table-wrap">
                    <table class="module-table activity-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date &amp; Time</th>
                                <th>Actor</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>Target User</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($logs === []): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <div class="icon">
                                                <i class="fas fa-clock-rotate-left"></i>
                                            </div>

                                            <h3>No activity logs found</h3>

                                            <p>
                                                Try changing the search term,
                                                action, or date range.
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $index => $log): ?>
                                    <?php
                                    $createdAt = strtotime(
                                        (string)$log['created_at']
                                    );

                                    $displayDate = $createdAt !== false
                                        ? date('d M Y, H:i', $createdAt)
                                        : '-';

                                    $actionLabel = ucwords(
                                        str_replace(
                                            '_',
                                            ' ',
                                            (string)$log['action']
                                        )
                                    );
                                    ?>

                                    <tr>
                                        <td class="text-muted">
                                            <?= number_format(
                                                $offset + $index + 1
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= activity_h($displayDate) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <div class="activity-user">
                                                <span class="activity-user-avatar">
                                                    <?= activity_h(
                                                        strtoupper(
                                                            substr(
                                                                (string)(
                                                                    $log['actor_name']
                                                                    ?: 'S'
                                                                ),
                                                                0,
                                                                1
                                                            )
                                                        )
                                                    ) ?>
                                                </span>

                                                <span>
                                                    <strong>
                                                        <?= activity_h(
                                                            $log['actor_name']
                                                            ?: 'System'
                                                        ) ?>
                                                    </strong>

                                                    <?php if (
                                                        !empty(
                                                            $log['actor_username']
                                                        )
                                                    ): ?>
                                                        <small>
                                                            @<?= activity_h(
                                                                $log['actor_username']
                                                            ) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="activity-badge">
                                                <?= activity_h($actionLabel) ?>
                                            </span>
                                        </td>

                                        <td class="activity-description">
                                            <?= activity_h(
                                                $log['description']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?php if (
                                                !empty($log['target_name'])
                                            ): ?>
                                                <strong>
                                                    <?= activity_h(
                                                        $log['target_name']
                                                    ) ?>
                                                </strong>

                                                <?php if (
                                                    !empty(
                                                        $log['target_username']
                                                    )
                                                ): ?>
                                                    <small class="activity-username">
                                                        @<?= activity_h(
                                                            $log['target_username']
                                                        ) ?>
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <code class="activity-ip">
                                                <?= activity_h(
                                                    $log['ip_address']
                                                    ?: '-'
                                                ) ?>
                                            </code>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>

            <div class="activity-pagination">

                <div class="activity-pagination-summary">
                    Showing
                    <strong><?= $total > 0 ? number_format($offset + 1) : 0 ?></strong>
                    to
                    <strong>
                        <?= number_format(
                            min($offset + $perPage, $total)
                        ) ?>
                    </strong>
                    of
                    <strong><?= number_format($total) ?></strong>
                    activities
                </div>

                <?php if ($totalPages > 1): ?>
                    <?php
                    $query = $_GET;
                    unset($query['page']);

                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <nav
                        class="activity-pages"
                        aria-label="Activity log pagination"
                    >
                        <?php if ($page > 1): ?>
                            <a
                                href="<?= activity_h(
                                    activity_build_url(
                                        $query,
                                        $page - 1
                                    )
                                ) ?>"
                                aria-label="Previous page"
                            >
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php if ($startPage > 1): ?>
                            <a
                                href="<?= activity_h(
                                    activity_build_url($query, 1)
                                ) ?>"
                            >
                                1
                            </a>

                            <?php if ($startPage > 2): ?>
                                <span class="activity-page-ellipsis">…</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for (
                            $pageNumber = $startPage;
                            $pageNumber <= $endPage;
                            $pageNumber++
                        ): ?>
                            <a
                                href="<?= activity_h(
                                    activity_build_url(
                                        $query,
                                        $pageNumber
                                    )
                                ) ?>"
                                class="<?= $pageNumber === $page ? 'active' : '' ?>"
                                <?= $pageNumber === $page
                                    ? 'aria-current="page"'
                                    : '' ?>
                            >
                                <?= $pageNumber ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="activity-page-ellipsis">…</span>
                            <?php endif; ?>

                            <a
                                href="<?= activity_h(
                                    activity_build_url(
                                        $query,
                                        $totalPages
                                    )
                                ) ?>"
                            >
                                <?= $totalPages ?>
                            </a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="<?= activity_h(
                                    activity_build_url(
                                        $query,
                                        $page + 1
                                    )
                                ) ?>"
                                aria-label="Next page"
                            >
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
