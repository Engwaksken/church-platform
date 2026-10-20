<?php
declare(strict_types=1);

$pageTitle = 'Role and User Permissions';
require_once __DIR__ . '/../includes/header.php';
require_permission('users.manage');

/**
 * Role and user permission management.
 *
 * Administrators may select:
 *  - one or many users;
 *  - one or many roles;
 *  - one or many permissions.
 *
 * The same selected permissions are saved for every selected user and role.
 */

if (empty($_SESSION['permissions_csrf'])) {
    $_SESSION['permissions_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['permissions_csrf'];

function permissionIntIds(array $values): array
{
    $ids = [];

    foreach ($values as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function permissionRoles(array $values, array $allowedRoles): array
{
    $roles = [];

    foreach ($values as $value) {
        $role = strtolower(trim((string) $value));
        if ($role !== '' && in_array($role, $allowedRoles, true)) {
            $roles[$role] = $role;
        }
    }

    return array_values($roles);
}

function permissionPlaceholders(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

function permissionBind(mysqli_stmt $stmt, string $types, array $values): void
{
    $refs = [];
    $refs[] = $types;

    foreach ($values as $key => $value) {
        $refs[] = &$values[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function permissionRedirect(array $userIds, array $roles): never
{
    $query = [];

    foreach ($userIds as $userId) {
        $query[] = 'user_ids[]=' . rawurlencode((string) $userId);
    }

    foreach ($roles as $role) {
        $query[] = 'roles[]=' . rawurlencode($role);
    }

    $url = BASE_URL . 'users/permissions.php';
    if ($query !== []) {
        $url .= '?' . implode('&', $query);
    }

    redirect($url);
}

// Use roles found in the users table and retain the known application roles.
$knownRoles = ['admin', 'secretary', 'treasurer', 'youth_leader', 'member'];
$roleResult = $conn->query("SELECT DISTINCT LOWER(TRIM(role)) AS role FROM users WHERE role IS NOT NULL AND TRIM(role) <> '' ORDER BY role");
if ($roleResult) {
    while ($roleRow = $roleResult->fetch_assoc()) {
        $role = strtolower(trim((string) ($roleRow['role'] ?? '')));
        if ($role !== '') {
            $knownRoles[] = $role;
        }
    }
}
$knownRoles = array_values(array_unique($knownRoles));
sort($knownRoles);

$selectedUserIds = permissionIntIds((array) ($_GET['user_ids'] ?? []));
$selectedRoles   = permissionRoles((array) ($_GET['roles'] ?? []), $knownRoles);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        setFlash('The form session expired. Refresh the page and try again.', 'danger');
        permissionRedirect([], []);
    }

    $selectedUserIds = permissionIntIds((array) ($_POST['user_ids'] ?? []));
    $selectedRoles   = permissionRoles((array) ($_POST['roles'] ?? []), $knownRoles);
    $permissionIds   = permissionIntIds((array) ($_POST['permissions'] ?? []));

    if ($selectedUserIds === [] && $selectedRoles === []) {
        setFlash('Select at least one user or one role before saving permissions.', 'danger');
        permissionRedirect([], []);
    }

    // Keep only permission IDs that really exist.
    if ($permissionIds !== []) {
        $placeholders = permissionPlaceholders(count($permissionIds));
        $stmt = $conn->prepare("SELECT id FROM permissions WHERE id IN ($placeholders)");

        if (!$stmt) {
            setFlash('Permissions could not be validated: ' . $conn->error, 'danger');
            permissionRedirect($selectedUserIds, $selectedRoles);
        }

        permissionBind($stmt, str_repeat('i', count($permissionIds)), $permissionIds);
        $stmt->execute();
        $validResult = $stmt->get_result();
        $validPermissionIds = [];

        while ($row = $validResult->fetch_assoc()) {
            $validPermissionIds[] = (int) $row['id'];
        }

        $stmt->close();
        $permissionIds = $validPermissionIds;
    }

    try {
        $conn->begin_transaction();

        if ($selectedUserIds !== []) {
            $deleteUser = $conn->prepare('DELETE FROM user_permissions WHERE user_id = ?');
            $insertUser = $conn->prepare(
                'INSERT INTO user_permissions (user_id, permission_id, allowed)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)'
            );

            if (!$deleteUser || !$insertUser) {
                throw new RuntimeException($conn->error);
            }

            foreach ($selectedUserIds as $userId) {
                $deleteUser->bind_param('i', $userId);
                if (!$deleteUser->execute()) {
                    throw new RuntimeException($deleteUser->error);
                }

                foreach ($permissionIds as $permissionId) {
                    $insertUser->bind_param('ii', $userId, $permissionId);
                    if (!$insertUser->execute()) {
                        throw new RuntimeException($insertUser->error);
                    }
                }
            }

            $deleteUser->close();
            $insertUser->close();
        }

        if ($selectedRoles !== []) {
            $deleteRole = $conn->prepare('DELETE FROM role_permissions WHERE role = ?');
            $insertRole = $conn->prepare(
                'INSERT INTO role_permissions (role, permission_id, allowed)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)'
            );

            if (!$deleteRole || !$insertRole) {
                throw new RuntimeException($conn->error);
            }

            foreach ($selectedRoles as $role) {
                // Admin still has full access in access.php, but its saved selections
                // are retained here so the permissions page accurately displays them.
                $deleteRole->bind_param('s', $role);
                if (!$deleteRole->execute()) {
                    throw new RuntimeException($deleteRole->error);
                }

                foreach ($permissionIds as $permissionId) {
                    $insertRole->bind_param('si', $role, $permissionId);
                    if (!$insertRole->execute()) {
                        throw new RuntimeException($insertRole->error);
                    }
                }
            }

            $deleteRole->close();
            $insertRole->close();
        }

        $conn->commit();

        $targetParts = [];
        if ($selectedUserIds !== []) {
            $targetParts[] = count($selectedUserIds) . ' user(s)';
        }
        if ($selectedRoles !== []) {
            $targetParts[] = count($selectedRoles) . ' role(s)';
        }

        setFlash(
            'Permissions saved successfully for ' . implode(' and ', $targetParts) . '. '
            . count($permissionIds) . ' permission(s) are assigned.',
            'success'
        );
    } catch (Throwable $exception) {
        $conn->rollback();
        setFlash('Permissions were not saved: ' . $exception->getMessage(), 'danger');
    }

    // PRG redirect retains selected users and roles. The saved permissions are
    // then reloaded from the database and remain checked after saving.
    permissionRedirect($selectedUserIds, $selectedRoles);
}

$users = [];
$userResult = $conn->query(
    "SELECT id, full_name, username, LOWER(TRIM(role)) AS role, status
     FROM users
     ORDER BY role, full_name, username"
);

if ($userResult) {
    while ($row = $userResult->fetch_assoc()) {
        $users[] = $row;
    }
}

$permissions = [];
$permissionResult = $conn->query(
    'SELECT id, permission_key, permission_name, module_name
     FROM permissions
     ORDER BY module_name, permission_name'
);

if ($permissionResult) {
    while ($row = $permissionResult->fetch_assoc()) {
        $permissions[] = $row;
    }
}

$targetPermissionCounts = [];
$totalTargets = count($selectedUserIds) + count($selectedRoles);

if ($selectedUserIds !== []) {
    $placeholders = permissionPlaceholders(count($selectedUserIds));
    $stmt = $conn->prepare(
        "SELECT permission_id, COUNT(DISTINCT user_id) AS assigned_count
         FROM user_permissions
         WHERE allowed = 1 AND user_id IN ($placeholders)
         GROUP BY permission_id"
    );

    if ($stmt) {
        permissionBind($stmt, str_repeat('i', count($selectedUserIds)), $selectedUserIds);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $permissionId = (int) $row['permission_id'];
            $targetPermissionCounts[$permissionId] =
                ($targetPermissionCounts[$permissionId] ?? 0) + (int) $row['assigned_count'];
        }

        $stmt->close();
    }
}

if ($selectedRoles !== []) {
    $placeholders = permissionPlaceholders(count($selectedRoles));
    $stmt = $conn->prepare(
        "SELECT permission_id, COUNT(DISTINCT role) AS assigned_count
         FROM role_permissions
         WHERE allowed = 1 AND role IN ($placeholders)
         GROUP BY permission_id"
    );

    if ($stmt) {
        permissionBind($stmt, str_repeat('s', count($selectedRoles)), $selectedRoles);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $permissionId = (int) $row['permission_id'];
            $targetPermissionCounts[$permissionId] =
                ($targetPermissionCounts[$permissionId] ?? 0) + (int) $row['assigned_count'];
        }

        $stmt->close();
    }
}

$permissionsByModule = [];
foreach ($permissions as $permission) {
    $module = trim((string) ($permission['module_name'] ?? 'Other')) ?: 'Other';
    $permissionsByModule[$module][] = $permission;
}
?>

<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="content-wrapper">
        <?= showFlash() ?>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3 style="margin-bottom:4px;">Assign Role and User Permissions</h3>
                    <small>Select multiple users, multiple roles, or both, then choose the permissions to assign.</small>
                </div>
            </div>

            <div class="card-body">
                <form method="get" id="targetSelectionForm">
                    <div class="permission-target-grid">
                        <div>
                            <label for="user_ids"><strong>Users</strong></label>
                            <input
                                type="search"
                                id="userSearch"
                                class="form-control permission-search"
                                placeholder="Search users..."
                                autocomplete="off"
                            >
                            <select
                                name="user_ids[]"
                                id="user_ids"
                                class="form-control permission-multi-select"
                                multiple
                                size="9"
                            >
                                <?php foreach ($users as $user): ?>
                                    <?php
                                    $userId = (int) $user['id'];
                                    $userRole = (string) ($user['role'] ?? '');
                                    $label = trim((string) ($user['full_name'] ?? ''));
                                    if ($label === '') {
                                        $label = (string) ($user['username'] ?? 'User');
                                    }
                                    ?>
                                    <option
                                        value="<?= $userId ?>"
                                        data-search="<?= h(strtolower($label . ' ' . ($user['username'] ?? '') . ' ' . $userRole)) ?>"
                                        <?= in_array($userId, $selectedUserIds, true) ? 'selected' : '' ?>
                                    >
                                        <?= h($label . ' — ' . ($user['username'] ?? '') . ' [' . ($userRole ?: 'no role') . ']') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>Hold Ctrl on Windows or Command on Mac to select several users.</small>
                        </div>

                        <div>
                            <label for="roles"><strong>Roles</strong></label>
                            <select
                                name="roles[]"
                                id="roles"
                                class="form-control permission-multi-select"
                                multiple
                                size="9"
                            >
                                <?php foreach ($knownRoles as $role): ?>
                                    <option
                                        value="<?= h($role) ?>"
                                        <?= in_array($role, $selectedRoles, true) ? 'selected' : '' ?>
                                    >
                                        <?= h(ucwords(str_replace('_', ' ', $role))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>A role permission applies to every user with that role.</small>
                        </div>
                    </div>

                    <div class="permission-target-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-eye"></i> Load Assigned Permissions
                        </button>
                        <a href="<?= h(BASE_URL . 'users/permissions.php') ?>" class="btn btn-secondary">
                            <i class="fas fa-rotate-left"></i> Clear Selection
                        </a>
                    </div>
                </form>

                <?php if ($totalTargets > 0): ?>
                    <hr style="margin:24px 0;">

                    <form method="post" id="permissionsForm">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                        <?php foreach ($selectedUserIds as $userId): ?>
                            <input type="hidden" name="user_ids[]" value="<?= $userId ?>">
                        <?php endforeach; ?>

                        <?php foreach ($selectedRoles as $role): ?>
                            <input type="hidden" name="roles[]" value="<?= h($role) ?>">
                        <?php endforeach; ?>

                        <div class="permission-summary">
                            <strong>Editing permissions for:</strong>
                            <?= count($selectedUserIds) ?> selected user(s) and
                            <?= count($selectedRoles) ?> selected role(s).
                            <br>
                            <small>
                                A checked permission is currently assigned to every selected target.
                                A partially assigned permission is marked below and becomes fully assigned when checked and saved.
                            </small>
                        </div>

                        <div class="permission-toolbar">
                            <button type="button" class="btn btn-secondary" id="selectAllPermissions">
                                <i class="fas fa-square-check"></i> Select All
                            </button>
                            <button type="button" class="btn btn-secondary" id="clearAllPermissions">
                                <i class="fas fa-square"></i> Clear All
                            </button>
                            <input
                                type="search"
                                id="permissionSearch"
                                class="form-control"
                                placeholder="Search permissions..."
                                autocomplete="off"
                            >
                        </div>

                        <?php if ($permissionsByModule === []): ?>
                            <div class="alert alert-warning">No permissions are available in the permissions table.</div>
                        <?php else: ?>
                            <div class="permission-modules" id="permissionModules">
                                <?php foreach ($permissionsByModule as $module => $modulePermissions): ?>
                                    <section class="permission-module" data-module="<?= h(strtolower($module)) ?>">
                                        <div class="permission-module-heading">
                                            <h4><?= h($module) ?></h4>
                                            <label>
                                                <input type="checkbox" class="module-toggle">
                                                Select module
                                            </label>
                                        </div>

                                        <div class="permission-grid">
                                            <?php foreach ($modulePermissions as $permission): ?>
                                                <?php
                                                $permissionId = (int) $permission['id'];
                                                $assignedCount = (int) ($targetPermissionCounts[$permissionId] ?? 0);
                                                $checked = $totalTargets > 0 && $assignedCount === $totalTargets;
                                                $partial = $assignedCount > 0 && $assignedCount < $totalTargets;
                                                $searchText = strtolower(
                                                    $module . ' '
                                                    . ($permission['permission_name'] ?? '') . ' '
                                                    . ($permission['permission_key'] ?? '')
                                                );
                                                ?>
                                                <label
                                                    class="permission-item<?= $partial ? ' permission-partial' : '' ?>"
                                                    data-search="<?= h($searchText) ?>"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        class="permission-checkbox"
                                                        name="permissions[]"
                                                        value="<?= $permissionId ?>"
                                                        <?= $checked ? 'checked' : '' ?>
                                                        data-partial="<?= $partial ? '1' : '0' ?>"
                                                    >
                                                    <span>
                                                        <strong><?= h((string) $permission['permission_name']) ?></strong>
                                                        <small><?= h((string) $permission['permission_key']) ?></small>
                                                        <?php if ($partial): ?>
                                                            <em>
                                                                Assigned to <?= $assignedCount ?> of <?= $totalTargets ?> selected targets
                                                            </em>
                                                        <?php endif; ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="permission-save-bar">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Permissions
                            </button>
                            <span id="selectedPermissionCount"></span>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info" style="margin-top:22px;">
                        Select one or more users or roles, then click <strong>Load Assigned Permissions</strong>.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.permission-target-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(260px, 1fr));
    gap: 20px;
}

.permission-multi-select {
    min-height: 230px;
    padding: 8px;
}

.permission-search {
    margin-bottom: 8px;
}

.permission-target-actions,
.permission-toolbar,
.permission-save-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
}

.permission-summary {
    padding: 14px 16px;
    border: 1px solid #cfe2ff;
    border-radius: 8px;
    background: #eef6ff;
}

.permission-toolbar {
    justify-content: flex-start;
    margin: 18px 0;
}

.permission-toolbar .form-control {
    max-width: 360px;
}

.permission-modules {
    display: grid;
    gap: 18px;
}

.permission-module {
    border: 1px solid #e1e5ea;
    border-radius: 10px;
    overflow: hidden;
    background: #fff;
}

.permission-module-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 12px 15px;
    background: #f6f8fa;
    border-bottom: 1px solid #e1e5ea;
}

.permission-module-heading h4 {
    margin: 0;
}

.permission-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(255px, 1fr));
    gap: 10px;
    padding: 14px;
}

.permission-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 0;
    padding: 12px;
    border: 1px solid #dfe3e8;
    border-radius: 8px;
    cursor: pointer;
    transition: border-color .15s ease, background .15s ease;
}

.permission-item:hover {
    border-color: #0d6efd;
    background: #f8fbff;
}

.permission-item input {
    margin-top: 4px;
}

.permission-item span,
.permission-item small,
.permission-item em {
    display: block;
}

.permission-item small {
    margin-top: 3px;
    color: #6c757d;
}

.permission-item em {
    margin-top: 5px;
    color: #9a6700;
    font-size: 12px;
    font-style: normal;
}

.permission-partial {
    border-style: dashed;
    border-color: #d39e00;
    background: #fffbea;
}

.permission-save-bar {
    position: sticky;
    bottom: 0;
    z-index: 5;
    justify-content: space-between;
    margin-top: 22px;
    padding: 14px;
    border: 1px solid #dfe3e8;
    border-radius: 9px;
    background: rgba(255, 255, 255, .96);
    box-shadow: 0 -3px 12px rgba(0, 0, 0, .06);
}

@media (max-width: 800px) {
    .permission-target-grid {
        grid-template-columns: 1fr;
    }

    .permission-module-heading {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<script>
(function () {
    'use strict';

    const userSearch = document.getElementById('userSearch');
    const userSelect = document.getElementById('user_ids');

    if (userSearch && userSelect) {
        userSearch.addEventListener('input', function () {
            const needle = this.value.trim().toLowerCase();

            Array.from(userSelect.options).forEach(function (option) {
                const matches = needle === '' || (option.dataset.search || '').includes(needle);
                option.hidden = !matches;
            });
        });
    }

    const permissionForm = document.getElementById('permissionsForm');
    if (!permissionForm) {
        return;
    }

    const permissionCheckboxes = Array.from(
        permissionForm.querySelectorAll('.permission-checkbox')
    );
    const selectedCount = document.getElementById('selectedPermissionCount');
    const permissionSearch = document.getElementById('permissionSearch');

    // Visually mark permissions assigned to only some selected targets.
    permissionCheckboxes.forEach(function (checkbox) {
        if (checkbox.dataset.partial === '1' && !checkbox.checked) {
            checkbox.indeterminate = true;
        }

        checkbox.addEventListener('change', function () {
            this.indeterminate = false;
            this.dataset.partial = '0';
            const item = this.closest('.permission-item');
            if (item) {
                item.classList.remove('permission-partial');
            }
            updateCountAndModules();
        });
    });

    function visiblePermissionCheckboxes() {
        return permissionCheckboxes.filter(function (checkbox) {
            const item = checkbox.closest('.permission-item');
            return item && item.style.display !== 'none';
        });
    }

    function updateCountAndModules() {
        const count = permissionCheckboxes.filter(function (checkbox) {
            return checkbox.checked;
        }).length;

        if (selectedCount) {
            selectedCount.textContent = count + ' permission(s) selected';
        }

        permissionForm.querySelectorAll('.permission-module').forEach(function (module) {
            const moduleCheckboxes = Array.from(module.querySelectorAll('.permission-checkbox'));
            const moduleToggle = module.querySelector('.module-toggle');
            const checkedCount = moduleCheckboxes.filter(function (checkbox) {
                return checkbox.checked;
            }).length;

            if (moduleToggle) {
                moduleToggle.checked = moduleCheckboxes.length > 0 && checkedCount === moduleCheckboxes.length;
                moduleToggle.indeterminate = checkedCount > 0 && checkedCount < moduleCheckboxes.length;
            }
        });
    }

    const selectAllButton = document.getElementById('selectAllPermissions');
    if (selectAllButton) {
        selectAllButton.addEventListener('click', function () {
            visiblePermissionCheckboxes().forEach(function (checkbox) {
                checkbox.checked = true;
                checkbox.indeterminate = false;
                checkbox.dataset.partial = '0';
                const item = checkbox.closest('.permission-item');
                if (item) item.classList.remove('permission-partial');
            });
            updateCountAndModules();
        });
    }

    const clearAllButton = document.getElementById('clearAllPermissions');
    if (clearAllButton) {
        clearAllButton.addEventListener('click', function () {
            visiblePermissionCheckboxes().forEach(function (checkbox) {
                checkbox.checked = false;
                checkbox.indeterminate = false;
                checkbox.dataset.partial = '0';
                const item = checkbox.closest('.permission-item');
                if (item) item.classList.remove('permission-partial');
            });
            updateCountAndModules();
        });
    }

    permissionForm.querySelectorAll('.module-toggle').forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            const module = this.closest('.permission-module');
            if (!module) return;

            module.querySelectorAll('.permission-checkbox').forEach(function (checkbox) {
                const item = checkbox.closest('.permission-item');
                if (item && item.style.display === 'none') return;

                checkbox.checked = toggle.checked;
                checkbox.indeterminate = false;
                checkbox.dataset.partial = '0';
                if (item) item.classList.remove('permission-partial');
            });

            updateCountAndModules();
        });
    });

    if (permissionSearch) {
        permissionSearch.addEventListener('input', function () {
            const needle = this.value.trim().toLowerCase();

            permissionForm.querySelectorAll('.permission-module').forEach(function (module) {
                let visibleItems = 0;

                module.querySelectorAll('.permission-item').forEach(function (item) {
                    const matches = needle === '' || (item.dataset.search || '').includes(needle);
                    item.style.display = matches ? '' : 'none';
                    if (matches) visibleItems++;
                });

                module.style.display = visibleItems > 0 ? '' : 'none';
            });
        });
    }

    permissionForm.addEventListener('submit', function (event) {
        const targetCount = <?= (int) $totalTargets ?>;
        if (targetCount < 1) {
            event.preventDefault();
            alert('Select at least one user or role.');
            return;
        }

        const confirmed = window.confirm(
            'Save the selected permissions for all selected users and roles? Existing direct permissions for those targets will be replaced.'
        );

        if (!confirmed) {
            event.preventDefault();
        }
    });

    updateCountAndModules();
}());
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
