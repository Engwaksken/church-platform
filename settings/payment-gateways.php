<?php
declare(strict_types=1);

$pageTitle = 'Payment Gateways';

require_once __DIR__ . '/../includes/header.php';
require_permission('settings.payment_gateways.manage');

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('pg_h')) {
    function pg_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pg_bind')) {
    function pg_bind(mysqli_stmt $stmt, string $types, array &$params): void
    {
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

if (!function_exists('pg_redirect')) {
    function pg_redirect(string $type, string $message): never
    {
        $_SESSION['payment_gateway_flash'] = [
            'type' => $type,
            'message' => $message,
        ];

        $query = $_GET;
        unset($query['edit'], $query['view'], $query['delete']);

        $url = 'payment-gateways.php';

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        header('Location: ' . $url);
        exit;
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS payment_gateways (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        gateway_type VARCHAR(50) NOT NULL DEFAULT 'bank',
        gateway_name VARCHAR(150) NOT NULL,
        provider_name VARCHAR(150) NOT NULL,
        account_name VARCHAR(150) NULL,
        account_number VARCHAR(100) NULL,
        phone_number VARCHAR(50) NULL,
        merchant_code VARCHAR(100) NULL,
        branch_name VARCHAR(150) NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
        payment_reference_label VARCHAR(180) NULL,
        instructions TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_payment_gateway_type (gateway_type),
        KEY idx_payment_gateway_active (is_active),
        KEY idx_payment_gateway_sort (sort_order)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci"
);

if (empty($_SESSION['payment_gateways_csrf'])) {
    $_SESSION['payment_gateways_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['payment_gateways_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (
        $postedToken === ''
        || !hash_equals($csrfToken, $postedToken)
    ) {
        pg_redirect('error', 'The form session expired. Refresh and try again.');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'delete') {
            $id = max(0, (int)($_POST['id'] ?? 0));

            if ($id < 1) {
                throw new RuntimeException(
                    'Invalid payment gateway selected.'
                );
            }

            $stmt = $conn->prepare(
                'DELETE FROM payment_gateways WHERE id = ?'
            );

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to prepare the delete request.'
                );
            }

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            pg_redirect(
                'success',
                'Payment gateway deleted successfully.'
            );
        }

        if ($action === 'toggle') {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

            if ($id < 1) {
                throw new RuntimeException(
                    'Invalid payment gateway selected.'
                );
            }

            $stmt = $conn->prepare(
                'UPDATE payment_gateways
                 SET is_active = ?
                 WHERE id = ?'
            );

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to prepare the visibility update.'
                );
            }

            $stmt->bind_param('ii', $isActive, $id);
            $stmt->execute();
            $stmt->close();

            pg_redirect(
                'success',
                $isActive === 1
                    ? 'Payment gateway made visible to members.'
                    : 'Payment gateway hidden from members.'
            );
        }

        if ($action !== 'save') {
            throw new RuntimeException('Unsupported action requested.');
        }

        $id = max(0, (int)($_POST['id'] ?? 0));

        $gatewayType = trim(
            (string)($_POST['gateway_type'] ?? 'bank')
        );

        $allowedTypes = [
            'bank',
            'mobile_money',
            'merchant_code',
        ];

        if (!in_array($gatewayType, $allowedTypes, true)) {
            throw new RuntimeException('Select a valid gateway type.');
        }

        $gatewayName = trim(
            (string)($_POST['gateway_name'] ?? '')
        );

        $providerName = trim(
            (string)($_POST['provider_name'] ?? '')
        );

        $accountName = trim(
            (string)($_POST['account_name'] ?? '')
        );

        $accountNumber = trim(
            (string)($_POST['account_number'] ?? '')
        );

        $phoneNumber = trim(
            (string)($_POST['phone_number'] ?? '')
        );

        $merchantCode = trim(
            (string)($_POST['merchant_code'] ?? '')
        );

        $branchName = trim(
            (string)($_POST['branch_name'] ?? '')
        );

        $currency = strtoupper(
            trim((string)($_POST['currency'] ?? 'UGX'))
        );

        $referenceLabel = trim(
            (string)($_POST['payment_reference_label'] ?? '')
        );

        $instructions = trim(
            (string)($_POST['instructions'] ?? '')
        );

        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $createdBy = (int)($_SESSION['user_id'] ?? 0);

        if ($gatewayName === '' || $providerName === '') {
            throw new RuntimeException(
                'Gateway name and provider are required.'
            );
        }

        if ($gatewayType === 'bank') {
            if ($accountName === '' || $accountNumber === '') {
                throw new RuntimeException(
                    'Bank gateways require an account name and account number.'
                );
            }

            $phoneNumber = '';
            $merchantCode = '';
        }

        if ($gatewayType === 'mobile_money') {
            if ($phoneNumber === '') {
                throw new RuntimeException(
                    'Mobile Money gateways require a phone number.'
                );
            }

            $accountNumber = '';
            $merchantCode = '';
            $branchName = '';
        }

        if ($gatewayType === 'merchant_code') {
            if ($merchantCode === '') {
                throw new RuntimeException(
                    'Merchant Code gateways require a merchant code.'
                );
            }

            $accountNumber = '';
            $phoneNumber = '';
            $branchName = '';
        }

        if ($currency === '') {
            $currency = 'UGX';
        }

        if ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE payment_gateways
                 SET gateway_type = ?,
                     gateway_name = ?,
                     provider_name = ?,
                     account_name = ?,
                     account_number = ?,
                     phone_number = ?,
                     merchant_code = ?,
                     branch_name = ?,
                     currency = ?,
                     payment_reference_label = ?,
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
                'sssssssssssiii',
                $gatewayType,
                $gatewayName,
                $providerName,
                $accountName,
                $accountNumber,
                $phoneNumber,
                $merchantCode,
                $branchName,
                $currency,
                $referenceLabel,
                $instructions,
                $isActive,
                $sortOrder,
                $id
            );

            $stmt->execute();
            $stmt->close();

            pg_redirect(
                'success',
                'Payment gateway updated successfully.'
            );
        }

        $stmt = $conn->prepare(
            "INSERT INTO payment_gateways (
                gateway_type,
                gateway_name,
                provider_name,
                account_name,
                account_number,
                phone_number,
                merchant_code,
                branch_name,
                currency,
                payment_reference_label,
                instructions,
                is_active,
                sort_order,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare the insert request.'
            );
        }

        $stmt->bind_param(
            'sssssssssssiii',
            $gatewayType,
            $gatewayName,
            $providerName,
            $accountName,
            $accountNumber,
            $phoneNumber,
            $merchantCode,
            $branchName,
            $currency,
            $referenceLabel,
            $instructions,
            $isActive,
            $sortOrder,
            $createdBy
        );

        $stmt->execute();
        $stmt->close();

        pg_redirect(
            'success',
            'Payment gateway added successfully.'
        );
    } catch (Throwable $exception) {
        pg_redirect(
            'error',
            'Payment gateway was not saved: ' . $exception->getMessage()
        );
    }
}

$flash = $_SESSION['payment_gateway_flash'] ?? null;
unset($_SESSION['payment_gateway_flash']);

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
        gateway_name LIKE ?
        OR provider_name LIKE ?
        OR account_name LIKE ?
        OR account_number LIKE ?
        OR phone_number LIKE ?
        OR merchant_code LIKE ?
        OR branch_name LIKE ?
        OR payment_reference_label LIKE ?
        OR instructions LIKE ?
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
        $like,
        $like,
        $like
    );

    $types .= 'sssssssss';
}

$allowedTypes = [
    'bank',
    'mobile_money',
    'merchant_code',
];

if (
    $typeFilter !== ''
    && in_array($typeFilter, $allowedTypes, true)
) {
    $where[] = 'gateway_type = ?';
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
     FROM payment_gateways
     {$whereSql}"
);

if (!$countStmt) {
    http_response_code(500);
    exit('Unable to prepare the gateway count query.');
}

$countParams = $params;
pg_bind($countStmt, $types, $countParams);

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
     FROM payment_gateways
     {$whereSql}
     ORDER BY sort_order ASC, gateway_name ASC, id ASC
     LIMIT ? OFFSET ?"
);

if (!$listStmt) {
    http_response_code(500);
    exit('Unable to prepare the gateway list query.');
}

$listParams = array_merge($params, [$perPage, $offset]);
$listTypes = $types . 'ii';

pg_bind($listStmt, $listTypes, $listParams);

$listStmt->execute();

$result = $listStmt->get_result();

$gateways = $result
    ? $result->fetch_all(MYSQLI_ASSOC)
    : [];

$listStmt->close();

$statsResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(is_active = 1) AS active_total,
        SUM(gateway_type = 'bank') AS bank_total,
        SUM(gateway_type = 'mobile_money') AS mobile_total,
        SUM(gateway_type = 'merchant_code') AS merchant_total
     FROM payment_gateways"
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
    href="<?= pg_h(BASE_URL . 'assets/css/payment-gateways.css') ?>"
>

<div class="main-content">
    <?php require_once __DIR__ . '/../includes/topbar.php'; ?>

    <div class="content-area payment-gateways-page">

        <?php if (is_array($flash)): ?>
            <div
                class="alert <?= ($flash['type'] ?? '') === 'success'
                    ? 'alert-success'
                    : 'alert-danger' ?>"
            >
                <span><?= pg_h($flash['message'] ?? '') ?></span>
            </div>
        <?php endif; ?>

        <div class="module-page-header">
            <div class="module-title">
                <h2>
                    <i class="fas fa-credit-card"></i>
                    Payment Gateways
                </h2>

                <p>
                    Configure bank accounts, Mobile Money numbers and
                    merchant codes shown to members.
                </p>
            </div>

            <div class="module-actions">
                <button
                    type="button"
                    class="btn btn-primary"
                    id="openAddGatewayModal"
                >
                    <i class="fas fa-plus"></i>
                    Add Payment Gateway
                </button>
            </div>
        </div>

        <div class="stats-grid module-stats-grid gateway-stats-grid">

            <div class="stat-card gateway-stat-card">
                <div class="stat-icon">
                    <i class="fas fa-wallet"></i>
                </div>

                <div class="stat-body">
                    <div class="stat-number">
                        <?= number_format((int)($stats['total'] ?? 0)) ?>
                    </div>

                    <div class="stat-label">Total Gateways</div>
                </div>
            </div>

            <div class="stat-card gateway-stat-card">
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

            <div class="stat-card gateway-stat-card">
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

            <div class="stat-card gateway-stat-card">
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
                    id="gatewayFilterForm"
                >
                    <div class="page-toolbar-search">
                        <i class="fas fa-search"></i>

                        <input
                            type="search"
                            name="search"
                            value="<?= pg_h($search) ?>"
                            placeholder="Search gateway, provider, account or code..."
                            autocomplete="off"
                        >
                    </div>

                    <div class="page-toolbar-filters">

                    <select
                        name="type"
                        class="form-control"
                        aria-label="Filter by gateway type"
                    >
                        <option value="">All Types</option>

                        <option
                            value="bank"
                            <?= $typeFilter === 'bank' ? 'selected' : '' ?>
                        >
                            Bank Account
                        </option>

                        <option
                            value="mobile_money"
                            <?= $typeFilter === 'mobile_money' ? 'selected' : '' ?>
                        >
                            Mobile Money
                        </option>

                        <option
                            value="merchant_code"
                            <?= $typeFilter === 'merchant_code' ? 'selected' : '' ?>
                        >
                            Merchant Code
                        </option>
                    </select>

                    <select
                        name="status"
                        class="form-control"
                        aria-label="Filter by visibility"
                    >
                        <option value="">All Statuses</option>

                        <option
                            value="active"
                            <?= $statusFilter === 'active' ? 'selected' : '' ?>
                        >
                            Visible
                        </option>

                        <option
                            value="inactive"
                            <?= $statusFilter === 'inactive' ? 'selected' : '' ?>
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

                        <a href="payment-gateways.php" class="btn btn-outline-primary page-toolbar-refresh">
                            <i class="fas fa-rotate-right"></i> Refresh
                        </a>
                    </div>
                </form>

        <div class="card module-card">

            <div class="module-card-header">
                <div>
                    <strong>
                        <i class="fas fa-money-check-dollar"></i>
                        Configured Payment Gateways
                    </strong>

                    <p class="gateway-card-description">
                        Search, filter and manage member-facing gateways.
                    </p>
                </div>

                <span class="module-count">
                    <?= number_format($total) ?> records
                </span>
            </div>

            <div class="card-body">

                <div class="module-table-wrap">
                    <table class="module-table gateway-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Gateway</th>
                                <th>Provider</th>
                                <th>Payment Details</th>
                                <th>Currency</th>
                                <th>Status</th>
                                <th>Order</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($gateways === []): ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <div class="icon">
                                                <i class="fas fa-credit-card"></i>
                                            </div>

                                            <h3>No payment gateways found</h3>

                                            <p>
                                                Add a gateway or change the
                                                current filters.
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($gateways as $index => $gateway): ?>
                                    <?php
                                    $typeLabel = match (
                                        (string)$gateway['gateway_type']
                                    ) {
                                        'mobile_money' => 'Mobile Money',
                                        'merchant_code' => 'Merchant Code',
                                        default => 'Bank Account',
                                    };

                                    $details = match (
                                        (string)$gateway['gateway_type']
                                    ) {
                                        'mobile_money' => trim(
                                            (string)$gateway['phone_number']
                                        ),
                                        'merchant_code' => trim(
                                            (string)$gateway['merchant_code']
                                        ),
                                        default => trim(
                                            (string)$gateway['account_number']
                                        ),
                                    };

                                    $rowJson = json_encode(
                                        $gateway,
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
                                            <strong>
                                                <?= pg_h($gateway['gateway_name']) ?>
                                            </strong>

                                            <small class="gateway-type-label">
                                                <?= pg_h($typeLabel) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?= pg_h($gateway['provider_name']) ?>
                                        </td>

                                        <td>
                                            <code class="gateway-number">
                                                <?= pg_h($details ?: '-') ?>
                                            </code>

                                            <?php if (
                                                !empty($gateway['account_name'])
                                            ): ?>
                                                <small class="gateway-account-name">
                                                    <?= pg_h(
                                                        $gateway['account_name']
                                                    ) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= pg_h(
                                                $gateway['currency'] ?: 'UGX'
                                            ) ?>
                                        </td>

                                        <td>
                                            <span
                                                class="badge <?= (int)$gateway['is_active'] === 1
                                                    ? 'badge-success'
                                                    : 'badge-secondary' ?>"
                                            >
                                                <?= (int)$gateway['is_active'] === 1
                                                    ? 'Visible'
                                                    : 'Hidden' ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= number_format(
                                                (int)$gateway['sort_order']
                                            ) ?>
                                        </td>

                                        <td>
                                            <div class="table-actions">
                                                <button
                                                    type="button"
                                                    class="btn btn-info btn-sm btn-icon gateway-view-btn"
                                                    title="View"
                                                    data-gateway="<?= pg_h(
                                                        $rowJson ?: '{}'
                                                    ) ?>"
                                                >
                                                    <i class="fas fa-eye"></i>
                                                </button>

                                                <button
                                                    type="button"
                                                    class="btn btn-primary btn-sm btn-icon gateway-edit-btn"
                                                    title="Edit"
                                                    data-gateway="<?= pg_h(
                                                        $rowJson ?: '{}'
                                                    ) ?>"
                                                >
                                                    <i class="fas fa-edit"></i>
                                                </button>

                                                <button
                                                    type="button"
                                                    class="btn btn-secondary btn-sm btn-icon gateway-toggle-btn"
                                                    title="<?= (int)$gateway['is_active'] === 1
                                                        ? 'Hide'
                                                        : 'Show' ?>"
                                                    data-id="<?= (int)$gateway['id'] ?>"
                                                    data-name="<?= pg_h(
                                                        $gateway['gateway_name']
                                                    ) ?>"
                                                    data-current="<?= (int)$gateway['is_active'] ?>"
                                                >
                                                    <i class="fas <?= (int)$gateway['is_active'] === 1
                                                        ? 'fa-eye-slash'
                                                        : 'fa-eye' ?>"></i>
                                                </button>

                                                <button
                                                    type="button"
                                                    class="btn btn-danger btn-sm btn-icon gateway-delete-btn"
                                                    title="Delete"
                                                    data-id="<?= (int)$gateway['id'] ?>"
                                                    data-name="<?= pg_h(
                                                        $gateway['gateway_name']
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

            <div class="gateway-pagination">

                <div class="gateway-pagination-summary">
                    Showing
                    <strong><?= $total > 0 ? number_format($offset + 1) : 0 ?></strong>
                    to
                    <strong><?= number_format(min($offset + $perPage, $total)) ?></strong>
                    of
                    <strong><?= number_format($total) ?></strong>
                    payment gateways
                </div>

                <?php if ($totalPages > 1): ?>
                    <?php
                    $query = $_GET;
                    unset($query['page']);

                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <nav
                        class="gateway-pages"
                        aria-label="Payment gateway pagination"
                    >
                        <?php if ($page > 1): ?>
                            <?php $query['page'] = $page - 1; ?>
                            <a
                                href="?<?= pg_h(http_build_query($query)) ?>"
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
                            <?php $query['page'] = $pageNumber; ?>
                            <a
                                href="?<?= pg_h(http_build_query($query)) ?>"
                                class="<?= $pageNumber === $page
                                    ? 'active'
                                    : '' ?>"
                            >
                                <?= $pageNumber ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <?php $query['page'] = $page + 1; ?>
                            <a
                                href="?<?= pg_h(http_build_query($query)) ?>"
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
    class="gateway-modal"
    id="gatewayFormModal"
    aria-hidden="true"
>
    <div class="gateway-dialog gateway-dialog-lg">
        <form method="post" id="gatewayForm" autocomplete="off">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= pg_h($csrfToken) ?>"
            >

            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="gatewayId" value="0">

            <div class="gateway-modal-header">
                <h3 id="gatewayFormTitle">
                    <i class="fas fa-plus"></i>
                    Add Payment Gateway
                </h3>

                <button
                    type="button"
                    class="gateway-close"
                    data-close-gateway-modal
                    aria-label="Close"
                >
                    &times;
                </button>
            </div>

            <div class="gateway-modal-body">
                <div class="form-grid">

                    <div class="form-group">
                        <label for="gatewayType">
                            Gateway Type
                            <span class="req">*</span>
                        </label>

                        <select
                            id="gatewayType"
                            name="gateway_type"
                            class="form-control"
                            required
                        >
                            <option value="bank">Bank Account</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="merchant_code">Merchant Code</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="gatewayName">
                            Display Name
                            <span class="req">*</span>
                        </label>

                        <input
                            type="text"
                            id="gatewayName"
                            name="gateway_name"
                            class="form-control"
                            maxlength="150"
                            required
                            placeholder="e.g. Church Stanbic Bank Account"
                        >
                    </div>

                    <div class="form-group">
                        <label for="providerName">
                            Bank / Network / Provider
                            <span class="req">*</span>
                        </label>

                        <input
                            type="text"
                            id="providerName"
                            name="provider_name"
                            class="form-control"
                            maxlength="150"
                            required
                            placeholder="e.g. Stanbic Bank or MTN MoMo"
                        >
                    </div>

                    <div class="form-group">
                        <label for="accountName">Account Name</label>

                        <input
                            type="text"
                            id="accountName"
                            name="account_name"
                            class="form-control"
                            maxlength="150"
                        >
                    </div>

                    <div class="form-group gateway-field gateway-bank-field">
                        <label for="accountNumber">Account Number</label>

                        <input
                            type="text"
                            id="accountNumber"
                            name="account_number"
                            class="form-control"
                            maxlength="100"
                        >
                    </div>

                    <div class="form-group gateway-field gateway-bank-field">
                        <label for="branchName">Branch</label>

                        <input
                            type="text"
                            id="branchName"
                            name="branch_name"
                            class="form-control"
                            maxlength="150"
                        >
                    </div>

                    <div class="form-group gateway-field gateway-mobile-field">
                        <label for="phoneNumber">
                            Mobile Money Number
                        </label>

                        <input
                            type="text"
                            id="phoneNumber"
                            name="phone_number"
                            class="form-control"
                            maxlength="50"
                            placeholder="e.g. 2567XXXXXXXX"
                        >
                    </div>

                    <div class="form-group gateway-field gateway-merchant-field">
                        <label for="merchantCode">Merchant Code</label>

                        <input
                            type="text"
                            id="merchantCode"
                            name="merchant_code"
                            class="form-control"
                            maxlength="100"
                        >
                    </div>

                    <div class="form-group">
                        <label for="currency">Currency</label>

                        <input
                            type="text"
                            id="currency"
                            name="currency"
                            class="form-control"
                            maxlength="10"
                            value="UGX"
                        >
                    </div>

                    <div class="form-group">
                        <label for="sortOrder">Display Order</label>

                        <input
                            type="number"
                            id="sortOrder"
                            name="sort_order"
                            class="form-control"
                            value="0"
                        >
                    </div>

                    <div class="form-group full-span">
                        <label for="referenceLabel">
                            Payment Reference Label
                        </label>

                        <input
                            type="text"
                            id="referenceLabel"
                            name="payment_reference_label"
                            class="form-control"
                            maxlength="180"
                            placeholder="e.g. Use your member number"
                        >
                    </div>

                    <div class="form-group full-span">
                        <label for="instructions">
                            Payment Instructions
                        </label>

                        <textarea
                            id="instructions"
                            name="instructions"
                            class="form-control"
                            rows="4"
                            placeholder="Explain how members should complete the payment."
                        ></textarea>
                    </div>

                    <div class="form-group full-span">
                        <label class="gateway-visible-check">
                            <input
                                type="checkbox"
                                name="is_active"
                                id="gatewayIsActive"
                                value="1"
                                checked
                            >

                            <span>Visible to members</span>
                        </label>
                    </div>

                </div>
            </div>

            <div class="gateway-modal-footer">
                <button
                    type="button"
                    class="btn btn-outline"
                    data-close-gateway-modal
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Save Payment Gateway
                </button>
            </div>
        </form>
    </div>
</div>

<div
    class="gateway-modal"
    id="gatewayViewModal"
    aria-hidden="true"
>
    <div class="gateway-dialog">
        <div class="gateway-modal-header">
            <h3>
                <i class="fas fa-eye"></i>
                Payment Gateway Details
            </h3>

            <button
                type="button"
                class="gateway-close"
                data-close-gateway-modal
                aria-label="Close"
            >
                &times;
            </button>
        </div>

        <div class="gateway-modal-body">
            <div class="detail-grid" id="gatewayViewContent"></div>
        </div>

        <div class="gateway-modal-footer">
            <button
                type="button"
                class="btn btn-outline"
                data-close-gateway-modal
            >
                Close
            </button>
        </div>
    </div>
</div>

<div
    class="gateway-modal"
    id="gatewayToggleModal"
    aria-hidden="true"
>
    <div class="gateway-dialog gateway-dialog-sm">
        <form method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= pg_h($csrfToken) ?>"
            >

            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" id="toggleGatewayId">
            <input type="hidden" name="is_active" id="toggleGatewayState">

            <div class="gateway-modal-header">
                <h3>
                    <i class="fas fa-eye"></i>
                    Change Gateway Visibility
                </h3>

                <button
                    type="button"
                    class="gateway-close"
                    data-close-gateway-modal
                    aria-label="Close"
                >
                    &times;
                </button>
            </div>

            <div class="gateway-modal-body">
                <div class="info-box warning gateway-warning-box">
                    <i class="fas fa-circle-info"></i>

                    <div id="toggleGatewayMessage"></div>
                </div>
            </div>

            <div class="gateway-modal-footer">
                <button
                    type="button"
                    class="btn btn-outline"
                    data-close-gateway-modal
                >
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i>
                    Confirm
                </button>
            </div>
        </form>
    </div>
</div>

<div
    class="gateway-modal"
    id="gatewayDeleteModal"
    aria-hidden="true"
>
    <div class="gateway-dialog gateway-dialog-sm">
        <form method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= pg_h($csrfToken) ?>"
            >

            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteGatewayId">

            <div class="gateway-modal-header">
                <h3>
                    <i class="fas fa-trash"></i>
                    Delete Payment Gateway
                </h3>

                <button
                    type="button"
                    class="gateway-close"
                    data-close-gateway-modal
                    aria-label="Close"
                >
                    &times;
                </button>
            </div>

            <div class="gateway-modal-body">
                <div class="info-box danger gateway-warning-box">
                    <i class="fas fa-exclamation-triangle"></i>

                    <div>
                        Are you sure you want to delete
                        <strong id="deleteGatewayName"></strong>?
                        This action cannot be undone.
                    </div>
                </div>
            </div>

            <div class="gateway-modal-footer">
                <button
                    type="button"
                    class="btn btn-outline"
                    data-close-gateway-modal
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
    class="gateway-backdrop"
    id="gatewayBackdrop"
></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const formModal = document.getElementById('gatewayFormModal');
    const viewModal = document.getElementById('gatewayViewModal');
    const toggleModal = document.getElementById('gatewayToggleModal');
    const deleteModal = document.getElementById('gatewayDeleteModal');
    const backdrop = document.getElementById('gatewayBackdrop');

    const gatewayType = document.getElementById('gatewayType');
    const accountName = document.getElementById('accountName');
    const accountNumber = document.getElementById('accountNumber');
    const branchName = document.getElementById('branchName');
    const phoneNumber = document.getElementById('phoneNumber');
    const merchantCode = document.getElementById('merchantCode');

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
            .querySelectorAll('.gateway-modal')
            .forEach(function (modal) {
                modal.classList.remove('show');
                modal.setAttribute('aria-hidden', 'true');
            });

        backdrop?.classList.remove('show');
        document.body.classList.remove('cms-modal-open');
    }

    function parseGateway(button) {
        try {
            return JSON.parse(button.dataset.gateway || '{}');
        } catch (error) {
            return {};
        }
    }

    function updateGatewayFields() {
        const type = gatewayType?.value || 'bank';

        document
            .querySelectorAll('.gateway-field')
            .forEach(function (field) {
                field.hidden = true;
            });

        if (type === 'bank') {
            document
                .querySelectorAll('.gateway-bank-field')
                .forEach(function (field) {
                    field.hidden = false;
                });

            accountNumber.required = true;
            phoneNumber.required = false;
            merchantCode.required = false;
        }

        if (type === 'mobile_money') {
            document
                .querySelectorAll('.gateway-mobile-field')
                .forEach(function (field) {
                    field.hidden = false;
                });

            accountNumber.required = false;
            phoneNumber.required = true;
            merchantCode.required = false;
        }

        if (type === 'merchant_code') {
            document
                .querySelectorAll('.gateway-merchant-field')
                .forEach(function (field) {
                    field.hidden = false;
                });

            accountNumber.required = false;
            phoneNumber.required = false;
            merchantCode.required = true;
        }
    }

    document
        .getElementById('openAddGatewayModal')
        ?.addEventListener('click', function () {
            const form = document.getElementById('gatewayForm');

            form?.reset();

            document.getElementById('gatewayId').value = '0';
            document.getElementById('gatewayType').value = 'bank';
            document.getElementById('currency').value = 'UGX';
            document.getElementById('sortOrder').value = '0';
            document.getElementById('gatewayIsActive').checked = true;

            document.getElementById('gatewayFormTitle').innerHTML =
                '<i class="fas fa-plus"></i> Add Payment Gateway';

            updateGatewayFields();
            openModal(formModal);
        });

    document
        .querySelectorAll('.gateway-edit-btn')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                const gateway = parseGateway(button);

                document.getElementById('gatewayId').value =
                    gateway.id || 0;

                document.getElementById('gatewayType').value =
                    gateway.gateway_type || 'bank';

                document.getElementById('gatewayName').value =
                    gateway.gateway_name || '';

                document.getElementById('providerName').value =
                    gateway.provider_name || '';

                accountName.value = gateway.account_name || '';
                accountNumber.value = gateway.account_number || '';
                branchName.value = gateway.branch_name || '';
                phoneNumber.value = gateway.phone_number || '';
                merchantCode.value = gateway.merchant_code || '';

                document.getElementById('currency').value =
                    gateway.currency || 'UGX';

                document.getElementById('referenceLabel').value =
                    gateway.payment_reference_label || '';

                document.getElementById('instructions').value =
                    gateway.instructions || '';

                document.getElementById('sortOrder').value =
                    gateway.sort_order || 0;

                document.getElementById('gatewayIsActive').checked =
                    Number(gateway.is_active) === 1;

                document.getElementById('gatewayFormTitle').innerHTML =
                    '<i class="fas fa-edit"></i> Edit Payment Gateway';

                updateGatewayFields();
                openModal(formModal);
            });
        });

    document
        .querySelectorAll('.gateway-view-btn')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                const gateway = parseGateway(button);

                const typeLabel = {
                    bank: 'Bank Account',
                    mobile_money: 'Mobile Money',
                    merchant_code: 'Merchant Code'
                }[gateway.gateway_type] || 'Bank Account';

                const fields = [
                    ['Gateway Type', typeLabel],
                    ['Display Name', gateway.gateway_name],
                    ['Provider', gateway.provider_name],
                    ['Account Name', gateway.account_name || '-'],
                    ['Account Number', gateway.account_number || '-'],
                    ['Mobile Money Number', gateway.phone_number || '-'],
                    ['Merchant Code', gateway.merchant_code || '-'],
                    ['Branch', gateway.branch_name || '-'],
                    ['Currency', gateway.currency || 'UGX'],
                    [
                        'Payment Reference',
                        gateway.payment_reference_label || '-'
                    ],
                    [
                        'Visibility',
                        Number(gateway.is_active) === 1
                            ? 'Visible to members'
                            : 'Hidden from members'
                    ],
                    ['Display Order', gateway.sort_order || 0],
                    ['Instructions', gateway.instructions || '-']
                ];

                const content = document.getElementById(
                    'gatewayViewContent'
                );

                content.innerHTML = '';

                fields.forEach(function (field) {
                    const item = document.createElement('div');
                    item.className = 'detail-item';

                    const label = document.createElement('b');
                    label.textContent = String(field[0]);

                    const value = document.createElement('span');
                    value.textContent = String(field[1] ?? '-');

                    item.appendChild(label);
                    item.appendChild(value);
                    content.appendChild(item);
                });

                openModal(viewModal);
            });
        });

    document
        .querySelectorAll('.gateway-toggle-btn')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                const current = Number(button.dataset.current || 0);
                const next = current === 1 ? 0 : 1;
                const name = button.dataset.name || 'this gateway';

                document.getElementById('toggleGatewayId').value =
                    button.dataset.id || '';

                document.getElementById('toggleGatewayState').value =
                    String(next);

                document.getElementById('toggleGatewayMessage').textContent =
                    next === 1
                        ? 'Make "' + name + '" visible to members?'
                        : 'Hide "' + name + '" from members?';

                openModal(toggleModal);
            });
        });

    document
        .querySelectorAll('.gateway-delete-btn')
        .forEach(function (button) {
            button.addEventListener('click', function () {
                document.getElementById('deleteGatewayId').value =
                    button.dataset.id || '';

                document.getElementById('deleteGatewayName').textContent =
                    button.dataset.name || 'this gateway';

                openModal(deleteModal);
            });
        });

    document
        .querySelectorAll('[data-close-gateway-modal]')
        .forEach(function (button) {
            button.addEventListener('click', closeModals);
        });

    backdrop?.addEventListener('click', closeModals);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModals();
        }
    });

    gatewayType?.addEventListener('change', updateGatewayFields);

    updateGatewayFields();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
