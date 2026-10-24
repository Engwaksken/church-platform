<?php
declare(strict_types=1);

$pageTitle = 'Member Payment Details';

require_once __DIR__ . '/../includes/header.php';
require_permission('settings.manage');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('mp_h')) {
    function mp_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('mp_bind')) {
    function mp_bind(mysqli_stmt $stmt, string $types, array &$params): void
    {
        if ($types === '' || $params === []) {
            return;
        }

        $args = [$types];

        foreach ($params as &$value) {
            $args[] = &$value;
        }

        unset($value);

        call_user_func_array([$stmt, 'bind_param'], $args);
    }
}

if (!function_exists('mp_redirect')) {
    function mp_redirect(string $type, string $message): never
    {
        $_SESSION['member_payment_flash'] = [
            'type' => $type,
            'message' => $message,
        ];

        $query = $_GET;
        unset($query['edit'], $query['view'], $query['delete']);

        $url = 'member-payments.php';

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        header('Location: ' . $url);
        exit;
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS member_payment_details (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        payment_type VARCHAR(50) NOT NULL DEFAULT 'Bank',
        provider_name VARCHAR(150) NOT NULL,
        account_name VARCHAR(150) NOT NULL,
        account_number VARCHAR(100) NOT NULL,
        branch_name VARCHAR(150) NULL,
        instructions TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_member_payment_type (payment_type),
        KEY idx_member_payment_active (is_active),
        KEY idx_member_payment_sort (sort_order)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci"
);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (
        $postedToken === ''
        || !hash_equals($csrfToken, $postedToken)
    ) {
        mp_redirect('error', 'Invalid request token. Please try again.');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'save') {
            $id = max(0, (int)($_POST['id'] ?? 0));

            $allowedTypes = [
                'Bank',
                'Mobile Money',
                'SACCO',
                'Other',
            ];

            $paymentType = trim(
                (string)($_POST['payment_type'] ?? 'Bank')
            );

            if (!in_array($paymentType, $allowedTypes, true)) {
                $paymentType = 'Other';
            }

            $providerName = trim(
                (string)($_POST['provider_name'] ?? '')
            );

            $accountName = trim(
                (string)($_POST['account_name'] ?? '')
            );

            $accountNumber = trim(
                (string)($_POST['account_number'] ?? '')
            );

            $branchName = trim(
                (string)($_POST['branch_name'] ?? '')
            );

            $instructions = trim(
                (string)($_POST['instructions'] ?? '')
            );

            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if (
                $providerName === ''
                || $accountName === ''
                || $accountNumber === ''
            ) {
                throw new RuntimeException(
                    'Provider, account name and account number are required.'
                );
            }

            if ($id > 0) {
                $stmt = $conn->prepare(
                    "UPDATE member_payment_details
                     SET payment_type = ?,
                         provider_name = ?,
                         account_name = ?,
                         account_number = ?,
                         branch_name = ?,
                         instructions = ?,
                         is_active = ?,
                         sort_order = ?
                     WHERE id = ?"
                );

                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to prepare the update request.'
                    );
                }

                $stmt->bind_param(
                    'ssssssiii',
                    $paymentType,
                    $providerName,
                    $accountName,
                    $accountNumber,
                    $branchName,
                    $instructions,
                    $isActive,
                    $sortOrder,
                    $id
                );

                $stmt->execute();
                $stmt->close();

                mp_redirect(
                    'success',
                    'Payment details updated successfully.'
                );
            }

            $stmt = $conn->prepare(
                "INSERT INTO member_payment_details (
                    payment_type,
                    provider_name,
                    account_name,
                    account_number,
                    branch_name,
                    instructions,
                    is_active,
                    sort_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to prepare the insert request.'
                );
            }

            $stmt->bind_param(
                'ssssssii',
                $paymentType,
                $providerName,
                $accountName,
                $accountNumber,
                $branchName,
                $instructions,
                $isActive,
                $sortOrder
            );

            $stmt->execute();
            $stmt->close();

            mp_redirect(
                'success',
                'Payment details added successfully.'
            );
        }

        if ($action === 'delete') {
            $id = max(0, (int)($_POST['id'] ?? 0));

            if ($id < 1) {
                throw new RuntimeException(
                    'Invalid payment detail selected.'
                );
            }

            $stmt = $conn->prepare(
                'DELETE FROM member_payment_details WHERE id = ?'
            );

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to prepare the delete request.'
                );
            }

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            mp_redirect(
                'success',
                'Payment details deleted successfully.'
            );
        }

        throw new RuntimeException('Unsupported action requested.');
    } catch (Throwable $exception) {
        mp_redirect('error', $exception->getMessage());
    }
}

$flash = $_SESSION['member_payment_flash'] ?? null;
unset($_SESSION['member_payment_flash']);

$search = trim((string)($_GET['search'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$page = max(1, (int)($_GET['page'] ?? 1));

$perPage = (int)($_GET['per_page'] ?? 10);

if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 10;
}

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = '(
        provider_name LIKE ?
        OR account_name LIKE ?
        OR account_number LIKE ?
        OR branch_name LIKE ?
        OR instructions LIKE ?
    )';

    $like = '%' . $search . '%';

    array_push(
        $params,
        $like,
        $like,
        $like,
        $like,
        $like
    );

    $types .= 'sssss';
}

$allowedTypes = [
    'Bank',
    'Mobile Money',
    'SACCO',
    'Other',
];

if (
    $typeFilter !== ''
    && in_array($typeFilter, $allowedTypes, true)
) {
    $where[] = 'payment_type = ?';
    $params[] = $typeFilter;
    $types .= 's';
} else {
    $typeFilter = '';
}

if ($statusFilter === 'active') {
    $where[] = 'is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'is_active = 0';
} else {
    $statusFilter = '';
}

$whereSql = $where !== []
    ? ' WHERE ' . implode(' AND ', $where)
    : '';

$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM member_payment_details
     {$whereSql}"
);

if (!$countStmt) {
    http_response_code(500);
    exit('Unable to prepare the payment count query.');
}

$countParams = $params;
mp_bind($countStmt, $types, $countParams);

$countStmt->execute();

$total = (int)(
    $countStmt->get_result()->fetch_assoc()['total'] ?? 0
);

$countStmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $conn->prepare(
    "SELECT *
     FROM member_payment_details
     {$whereSql}
     ORDER BY sort_order ASC, id ASC
     LIMIT ? OFFSET ?"
);

if (!$listStmt) {
    http_response_code(500);
    exit('Unable to prepare the payment list query.');
}

$listParams = array_merge($params, [$perPage, $offset]);
$listTypes = $types . 'ii';

mp_bind($listStmt, $listTypes, $listParams);

$listStmt->execute();

$result = $listStmt->get_result();

$rows = $result
    ? $result->fetch_all(MYSQLI_ASSOC)
    : [];

$listStmt->close();

$statsResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(is_active = 1) AS active_total,
        SUM(is_active = 0) AS inactive_total,
        SUM(payment_type = 'Bank') AS bank_total,
        SUM(payment_type = 'Mobile Money') AS mobile_total,
        SUM(payment_type = 'SACCO') AS sacco_total
     FROM member_payment_details"
);

$stats = $statsResult
    ? ($statsResult->fetch_assoc() ?: [])
    : [];

if ($statsResult) {
    $statsResult->free();
}

require_once __DIR__ . '/../includes/sidebar.php';
?>

<link
    rel="stylesheet"
    href="<?= mp_h(BASE_URL . 'assets/css/member-payments.css') ?>"
>

<div class="main-content">
    <?php require_once __DIR__ . '/../includes/topbar.php'; ?>

    <div class="content-area member-payments-page">

        <?php if (is_array($flash)): ?>
            <div
                class="alert <?= ($flash['type'] ?? '') === 'success'
                    ? 'alert-success'
                    : 'alert-danger' ?>"
            >
                <span>
                    <?= mp_h($flash['message'] ?? '') ?>
                </span>
            </div>
        <?php endif; ?>

        <div class="module-page-header">
            <div class="module-title">
                <h2>
                    <i class="fas fa-building-columns"></i>
                    Member Payment Details
                </h2>

                <p>
                    Manage bank, mobile money, SACCO and other payment details
                    visible to members.
                </p>
            </div>

            <div class="module-actions">
                <button
                    type="button"
                    class="btn btn-primary"
                    id="openAddPaymentModal"
                >
                    <i class="fas fa-plus"></i>
                    Add Payment Details
                </button>
            </div>
        </div>

        <div class="stats-grid module-stats-grid payment-stats-grid">

            <div class="stat-card payment-stat-card">
                <div class="stat-icon">
                    <i class="fas fa-wallet"></i>
                </div>

                <div class="stat-body">
                    <div class="stat-number">
                        <?= number_format((int)($stats['total'] ?? 0)) ?>
                    </div>

                    <div class="stat-label">Total Payment Methods</div>
                </div>
            </div>

            <div class="stat-card payment-stat-card">
                <div class="stat-icon">
                    <i class="fas fa-eye"></i>
                </div>

                <div class="stat-body">
                    <div class="stat-number">
                        <?= number_format((int)($stats['active_total'] ?? 0)) ?>
                    </div>

                    <div class="stat-label">Visible to Members</div>
                </div>
            </div>

            <div class="stat-card payment-stat-card">
                <div class="stat-icon">
                    <i class="fas fa-building-columns"></i>
                </div>

                <div class="stat-body">
                    <div class="stat-number">
                        <?= number_format((int)($stats['bank_total'] ?? 0)) ?>
                    </div>

                    <div class="stat-label">Bank Accounts</div>
                </div>
            </div>

            <div class="stat-card payment-stat-card">
                <div class="stat-icon">
                    <i class="fas fa-mobile-screen-button"></i>
                </div>

                <div class="stat-body">
                    <div class="stat-number">
                        <?= number_format((int)($stats['mobile_total'] ?? 0)) ?>
                    </div>

                    <div class="stat-label">Mobile Money</div>
                </div>
            </div>

        </div>

                <form
                    method="get"
                    class="page-toolbar"
                    id="paymentFilterForm"
                >
                    <div class="page-toolbar-search">
                        <i class="fas fa-search"></i>

                        <input
                            type="search"
                            name="search"
                            value="<?= mp_h($search) ?>"
                            placeholder="Search provider, account, number or branch..."
                            autocomplete="off"
                        >
                    </div>

                    <div class="page-toolbar-filters">

                    <select
                        name="type"
                        class="form-control"
                        aria-label="Filter by payment type"
                    >
                        <option value="">All Types</option>

                        <?php foreach ($allowedTypes as $paymentType): ?>
                            <option
                                value="<?= mp_h($paymentType) ?>"
                                <?= $typeFilter === $paymentType
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= mp_h($paymentType) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select
                        name="status"
                        class="form-control"
                        aria-label="Filter by visibility status"
                    >
                        <option value="">All Statuses</option>
                        <option
                            value="active"
                            <?= $statusFilter === 'active'
                                ? 'selected'
                                : '' ?>
                        >
                            Visible
                        </option>

                        <option
                            value="inactive"
                            <?= $statusFilter === 'inactive'
                                ? 'selected'
                                : '' ?>
                        >
                            Hidden
                        </option>
                    </select>

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

                        <a href="member-payments.php" class="btn btn-outline-primary page-toolbar-refresh">
                            <i class="fas fa-rotate-right"></i> Refresh
                        </a>
                    </div>
                </form>

        <div class="card module-card">

            <div class="module-card-header">
                <div>
                    <strong>
                        <i class="fas fa-credit-card"></i>
                        Payment Details Visible to Members
                    </strong>

                    <p class="payment-card-description">
                        Search, filter and manage member-facing payment options.
                    </p>
                </div>

                <span class="module-count">
                    <?= number_format($total) ?> records
                </span>
            </div>

            <div class="card-body">

                <div class="module-table-wrap">
                    <table class="module-table payment-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Payment Type</th>
                                <th>Provider</th>
                                <th>Account Name</th>
                                <th>Account / Phone</th>
                                <th>Branch</th>
                                <th>Visibility</th>
                                <th>Sort Order</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($rows === []): ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">
                                            <div class="icon">
                                                <i class="fas fa-wallet"></i>
                                            </div>

                                            <h3>No payment details found</h3>

                                            <p>
                                                Add a payment method or change
                                                the current filters.
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $index => $row): ?>
                                    <?php
                                    $rowJson = json_encode(
                                        $row,
                                        JSON_HEX_TAG
                                        | JSON_HEX_AMP
                                        | JSON_HEX_APOS
                                        | JSON_HEX_QUOT
                                    );
                                    ?>

                                    <tr>
                                        <td class="text-muted">
                                            <?= number_format(
                                                $offset + $index + 1
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="payment-type-badge">
                                                <?= mp_h($row['payment_type']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= mp_h($row['provider_name']) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= mp_h($row['account_name']) ?>
                                        </td>

                                        <td>
                                            <code class="payment-number">
                                                <?= mp_h($row['account_number']) ?>
                                            </code>
                                        </td>

                                        <td>
                                            <?= mp_h(
                                                $row['branch_name'] ?: '-'
                                            ) ?>
                                        </td>

                                        <td>
                                            <span
                                                class="badge <?= (int)$row['is_active'] === 1
                                                    ? 'badge-success'
                                                    : 'badge-secondary' ?>"
                                            >
                                                <?= (int)$row['is_active'] === 1
                                                    ? 'Visible'
                                                    : 'Hidden' ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= number_format(
                                                (int)$row['sort_order']
                                            ) ?>
                                        </td>

                                        <td>
                                            <div class="table-actions">
                                                <button
                                                    type="button"
                                                    class="btn btn-info btn-sm btn-icon payment-view-btn"
                                                    title="View"
                                                    data-payment="<?= mp_h(
                                                        $rowJson ?: '{}'
                                                    ) ?>"
                                                >
                                                    <i class="fas fa-eye"></i>
                                                </button>

                                                <button
                                                    type="button"
                                                    class="btn btn-primary btn-sm btn-icon payment-edit-btn"
                                                    title="Edit"
                                                    data-payment="<?= mp_h(
                                                        $rowJson ?: '{}'
                                                    ) ?>"
                                                >
                                                    <i class="fas fa-edit"></i>
                                                </button>

                                                <button
                                                    type="button"
                                                    class="btn btn-danger btn-sm btn-icon payment-delete-btn"
                                                    title="Delete"
                                                    data-id="<?= (int)$row['id'] ?>"
                                                    data-name="<?= mp_h(
                                                        $row['provider_name']
                                                    ) ?>"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>

            <div class="payment-pagination">

                <div class="payment-pagination-summary">
                    Showing
                    <strong><?= $total > 0 ? number_format($offset + 1) : 0 ?></strong>
                    to
                    <strong><?= number_format(min($offset + $perPage, $total)) ?></strong>
                    of
                    <strong><?= number_format($total) ?></strong>
                    payment details
                </div>

                <?php if ($totalPages > 1): ?>
                    <?php
                    $query = $_GET;
                    unset($query['page']);

                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <nav
                        class="payment-pages"
                        aria-label="Payment details pagination"
                    >
                        <?php if ($page > 1): ?>
                            <?php
                            $query['page'] = $page - 1;
                            ?>
                            <a
                                href="?<?= mp_h(http_build_query($query)) ?>"
                                aria-label="Previous page"
                            >
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php for (
                            $pageNumber = $startPage;
                            $pageNumber <= $endPage;
                            $pageNumber++
                        ): ?>
                            <?php
                            $query['page'] = $pageNumber;
                            ?>
                            <a
                                href="?<?= mp_h(http_build_query($query)) ?>"
                                class="<?= $pageNumber === $page
                                    ? 'active'
                                    : '' ?>"
                            >
                                <?= $pageNumber ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <?php
                            $query['page'] = $page + 1;
                            ?>
                            <a
                                href="?<?= mp_h(http_build_query($query)) ?>"
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

<div
    class="member-payment-modal"
    id="paymentFormModal"
    aria-hidden="true"
>
    <div class="member-payment-dialog">
        <form method="post" id="paymentDetailsForm" autocomplete="off">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= mp_h($csrfToken) ?>"
            >

            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="paymentId" value="0">

            <div class="member-payment-modal-header">
                <h3 id="paymentFormTitle">
                    <i class="fas fa-plus"></i>
                    Add Payment Details
                </h3>

                <button
                    type="button"
                    class="member-payment-close"
                    data-close-payment-modal
                    aria-label="Close"
                >
                    &times;
                </button>
            </div>

            <div class="member-payment-modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="paymentType">Payment Type</label>

                        <select
                            id="paymentType"
                            name="payment_type"
                            class="form-control"
                            required
                        >
                            <?php foreach ($allowedTypes as $paymentType): ?>
                                <option value="<?= mp_h($paymentType) ?>">
                                    <?= mp_h($paymentType) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="providerName">
                            Bank / Provider
                            <span class="req">*</span>
                        </label>

                        <input
                            type="text"
                            id="providerName"
                            name="provider_name"
                            class="form-control"
                            maxlength="150"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="accountName">
                            Account Name
                            <span class="req">*</span>
                        </label>

                        <input
                            type="text"
                            id="accountName"
                            name="account_name"
                            class="form-control"
                            maxlength="150"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="accountNumber">
                            Account / Phone Number
                            <span class="req">*</span>
                        </label>

                        <input
                            type="text"
                            id="accountNumber"
                            name="account_number"
                            class="form-control"
                            maxlength="100"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="branchName">Branch</label>

                        <input
                            type="text"
                            id="branchName"
                            name="branch_name"
                            class="form-control"
                            maxlength="150"
                        >
                    </div>

                    <div class="form-group">
                        <label for="sortOrder">Sort Order</label>

                        <input
                            type="number"
                            id="sortOrder"
                            name="sort_order"
                            class="form-control"
                            value="0"
                        >
                    </div>

                    <div class="form-group full-span">
                        <label for="instructions">
                            Deposit Instructions
                        </label>

                        <textarea
                            id="instructions"
                            name="instructions"
                            class="form-control"
                            rows="4"
                        ></textarea>
                    </div>

                    <div class="form-group full-span">
                        <label class="payment-visible-check">
                            <input
                                type="checkbox"
                                name="is_active"
                                id="paymentIsActive"
                                value="1"
                                checked
                            >

                            <span>Visible to members</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="member-payment-modal-footer">
                <button
                    type="button"
                    class="btn btn-outline"
                    data-close-payment-modal
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Save Payment Details
                </button>
            </div>
        </form>
    </div>
</div>

<div
    class="member-payment-modal"
    id="paymentViewModal"
    aria-hidden="true"
>
    <div class="member-payment-dialog">
        <div class="member-payment-modal-header">
            <h3>
                <i class="fas fa-eye"></i>
                Payment Details
            </h3>

            <button
                type="button"
                class="member-payment-close"
                data-close-payment-modal
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <div class="member-payment-modal-body">
            <div class="detail-grid" id="paymentViewContent"></div>
        </div>

        <div class="member-payment-modal-footer">
            <button
                type="button"
                class="btn btn-outline"
                data-close-payment-modal
            >
                Close
            </button>
        </div>
    </div>
</div>

<div
    class="member-payment-modal"
    id="paymentDeleteModal"
    aria-hidden="true"
>
    <div class="member-payment-dialog member-payment-dialog-sm">
        <form method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= mp_h($csrfToken) ?>"
            >

            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deletePaymentId">

            <div class="member-payment-modal-header">
                <h3>
                    <i class="fas fa-trash"></i>
                    Delete Payment Details
                </h3>

                <button
                    type="button"
                    class="member-payment-close"
                    data-close-payment-modal
                    aria-label="Close"
                >
                    &times;
                </button>
            </div>

            <div class="member-payment-modal-body">
                <div class="info-box danger payment-delete-warning">
                    <i class="fas fa-exclamation-triangle"></i>

                    <div>
                        Are you sure you want to delete the payment details for
                        <strong id="deletePaymentName"></strong>?
                        This action cannot be undone.
                    </div>
                </div>
            </div>

            <div class="member-payment-modal-footer">
                <button
                    type="button"
                    class="btn btn-outline"
                    data-close-payment-modal
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i>
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>

<div
    class="member-payment-backdrop"
    id="memberPaymentBackdrop"
></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const formModal = document.getElementById('paymentFormModal');
    const viewModal = document.getElementById('paymentViewModal');
    const deleteModal = document.getElementById('paymentDeleteModal');
    const backdrop = document.getElementById('memberPaymentBackdrop');

    function openModal(modal) {
        if (!modal || !backdrop) {
            return;
        }

        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        backdrop.classList.add('show');
        document.body.classList.add('cms-modal-open');
    }

    function closeModals() {
        document
            .querySelectorAll('.member-payment-modal')
            .forEach(function (modal) {
                modal.classList.remove('show');
                modal.setAttribute('aria-hidden', 'true');
            });

        backdrop?.classList.remove('show');
        document.body.classList.remove('cms-modal-open');
    }

    function parsePayment(button) {
        try {
            return JSON.parse(button.dataset.payment || '{}');
        } catch (error) {
            return {};
        }
    }

    document
        .getElementById('openAddPaymentModal')
        ?.addEventListener('click', function () {
            const form = document.getElementById('paymentDetailsForm');

            form?.reset();

            document.getElementById('paymentId').value = '0';
            document.getElementById('paymentType').value = 'Bank';
            document.getElementById('sortOrder').value = '0';
            document.getElementById('paymentIsActive').checked = true;

            document.getElementById('paymentFormTitle').innerHTML =
                '<i class="fas fa-plus"></i> Add Payment Details';

            openModal(formModal);
        });

    document
        .querySelectorAll('.payment-edit-btn')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                const payment = parsePayment(button);

                document.getElementById('paymentId').value =
                    payment.id || 0;

                document.getElementById('paymentType').value =
                    payment.payment_type || 'Bank';

                document.getElementById('providerName').value =
                    payment.provider_name || '';

                document.getElementById('accountName').value =
                    payment.account_name || '';

                document.getElementById('accountNumber').value =
                    payment.account_number || '';

                document.getElementById('branchName').value =
                    payment.branch_name || '';

                document.getElementById('sortOrder').value =
                    payment.sort_order || 0;

                document.getElementById('instructions').value =
                    payment.instructions || '';

                document.getElementById('paymentIsActive').checked =
                    Number(payment.is_active) === 1;

                document.getElementById('paymentFormTitle').innerHTML =
                    '<i class="fas fa-edit"></i> Edit Payment Details';

                openModal(formModal);
            });
        });

    document
        .querySelectorAll('.payment-view-btn')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                const payment = parsePayment(button);

                const fields = [
                    ['Payment Type', payment.payment_type],
                    ['Provider', payment.provider_name],
                    ['Account Name', payment.account_name],
                    ['Account / Phone', payment.account_number],
                    ['Branch', payment.branch_name || '-'],
                    [
                        'Visibility',
                        Number(payment.is_active) === 1
                            ? 'Visible to members'
                            : 'Hidden from members'
                    ],
                    ['Sort Order', payment.sort_order || 0],
                    ['Instructions', payment.instructions || '-']
                ];

                const content = document.getElementById(
                    'paymentViewContent'
                );

                content.innerHTML = fields.map(function (field) {
                    const label = String(field[0]);
                    const value = String(field[1] ?? '-');

                    const item = document.createElement('div');
                    item.className = 'detail-item';

                    const heading = document.createElement('b');
                    heading.textContent = label;

                    const text = document.createElement('span');
                    text.textContent = value;

                    item.appendChild(heading);
                    item.appendChild(text);

                    return item.outerHTML;
                }).join('');

                openModal(viewModal);
            });
        });

    document
        .querySelectorAll('.payment-delete-btn')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('deletePaymentId').value =
                    button.dataset.id || '';

                document.getElementById('deletePaymentName').textContent =
                    button.dataset.name || 'this provider';

                openModal(deleteModal);
            });
        });

    document
        .querySelectorAll('[data-close-payment-modal]')
        .forEach(function (button) {
            button.addEventListener('click', closeModals);
        });

    backdrop?.addEventListener('click', closeModals);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModals();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
