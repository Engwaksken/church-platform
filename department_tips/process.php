<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../church_admin/common.php';
require_once '../includes/member-content-functions.php';
requireLogin();
enforce_member_read_only();

/** Stops with 403 unless the current user manages $department's tips. */
function tip_require_department(string $department): void
{
    if (!mc_valid_department($department)) throw new RuntimeException('Invalid department.');
    require_permission(mc_tip_departments()[$department]['permission']);
}

function tip_load(mysqli $conn, int $id): array
{
    if ($id < 1) throw new RuntimeException('Invalid tip.');
    $stmt = $conn->prepare('SELECT id, department, status, published_at FROM department_tips WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $tip = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tip) throw new RuntimeException('Tip not found.');
    return $tip;
}

$department = (string)($_POST['department'] ?? '');
// Only tip managers may post here; the exact department is checked per action below.
if (!mc_manageable_departments()) require_permission(mc_tip_departments()[mc_valid_department($department) ? $department : 'health']['permission']);
try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new RuntimeException('Invalid request.');
    ce_verify_csrf();
    if (!mc_tables_ready($conn, ['department_tips'])) throw new RuntimeException('Run database/member_content.sql first.');
    $action = (string)($_POST['action'] ?? '');
    $uid = ce_user_id();

    if ($action === 'save_tip') {
        $id = (int)($_POST['id'] ?? 0);
        tip_require_department($department);
        $existing = $id > 0 ? tip_load($conn, $id) : null;
        // An existing tip may only be edited by a leader of the department it belongs to.
        if ($existing && $existing['department'] !== $department) tip_require_department($existing['department']);

        $title = mc_post_text('title', 'Title', 200, true);
        $content = mc_post_text('content', 'Tip content', 20000, true);
        $status = mc_post_status();
        $publishedAt = mc_published_at($status, $existing['status'] ?? null, $existing['published_at'] ?? null);

        if ($existing) {
            $stmt = $conn->prepare('UPDATE department_tips SET department=?, title=?, content=?, status=?, published_at=?, updated_by=? WHERE id=?');
            $stmt->bind_param('sssssii', $department, $title, $content, $status, $publishedAt, $uid, $id);
        } else {
            $stmt = $conn->prepare('INSERT INTO department_tips (department, title, content, status, published_at, created_by, updated_by) VALUES (?,?,?,?,?,?,?)');
            $stmt->bind_param('sssssii', $department, $title, $content, $status, $publishedAt, $uid, $uid);
        }
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $stmt->close();
        ce_flash('success', $status === 'Published' ? 'Tip saved and published.' : 'Tip saved as a draft.');

    } elseif ($action === 'set_tip_status') {
        $tip = tip_load($conn, (int)($_POST['id'] ?? 0));
        $department = $tip['department'];
        tip_require_department($department);
        $status = mc_post_status();
        $publishedAt = mc_published_at($status, $tip['status'], $tip['published_at']);
        $stmt = $conn->prepare('UPDATE department_tips SET status=?, published_at=?, updated_by=? WHERE id=?');
        $id = (int)$tip['id'];
        $stmt->bind_param('ssii', $status, $publishedAt, $uid, $id);
        $stmt->execute();
        $stmt->close();
        ce_flash('success', $status === 'Published' ? 'Tip published.' : 'Tip unpublished.');

    } elseif ($action === 'delete_tip') {
        $tip = tip_load($conn, (int)($_POST['id'] ?? 0));
        $department = $tip['department'];
        tip_require_department($department);
        $stmt = $conn->prepare('DELETE FROM department_tips WHERE id=?');
        $id = (int)$tip['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        ce_flash('success', 'Tip deleted.');

    } else {
        throw new RuntimeException('Invalid action.');
    }
} catch (Throwable $e) {
    ce_flash('danger', $e->getMessage());
}
ce_redirect('index.php' . (mc_valid_department($department) ? '?dept=' . rawurlencode($department) : ''));
