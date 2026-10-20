<?php
declare(strict_types=1);

function appointment_ensure_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS appointment_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        member_user_id INT NOT NULL,
        recipient_user_id INT NOT NULL,
        recipient_member_id INT NULL,
        department_id INT NULL,
        recipient_label VARCHAR(180) NOT NULL,
        subject VARCHAR(180) NOT NULL,
        message TEXT NOT NULL,
        preferred_at DATETIME NULL,
        status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
        review_comment TEXT NULL,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_appointment_member (member_id, created_at),
        INDEX idx_appointment_recipient (recipient_user_id, status),
        INDEX idx_appointment_recipient_member (recipient_member_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function appointment_targets(mysqli $conn): array
{
    $targets = [];
    $roleLabels = [
        'vicar'=>'Vicar', 'pastor'=>'Pastor', 'priest'=>'Priest', 'lay_leader'=>'Lay Leader',
        'lay leader'=>'Lay Leader', 'lay_reader'=>'Lay Reader', 'lay reader'=>'Lay Reader', 'layreader'=>'Lay Reader', 'rector'=>'Rector',
        'archdeacon'=>'Archdeacon', 'bishop'=>'Bishop', 'secretary'=>'Secretary',
        'treasurer'=>'Treasurer', 'youth_leader'=>'Youth Leader', 'youth leader'=>'Youth Leader',
        'admin'=>'Church Administrator', 'administrator'=>'Church Administrator', 'church_leader'=>'Church Leader', 'church leader'=>'Church Leader'
    ];

    if (appointment_table_exists($conn, 'users')) {
        $rows = appointment_rows($conn, "SELECT id,full_name,email,LOWER(TRIM(role)) AS role FROM users WHERE email IS NOT NULL AND TRIM(email)<>'' AND (status IS NULL OR LOWER(status) IN ('active','1','enabled')) ORDER BY full_name");
        foreach ($rows as $row) {
            $role = (string)$row['role'];
            if (!isset($roleLabels[$role])) continue;
            $name = trim((string)$row['full_name']) ?: $roleLabels[$role];
            $targets['user:' . (int)$row['id']] = [
                'key'=>'user:' . (int)$row['id'], 'label'=>$roleLabels[$role] . ' — ' . $name,
                'recipient_user_id'=>(int)$row['id'], 'recipient_member_id'=>null, 'department_id'=>null,
                'recipient_label'=>$roleLabels[$role] . ' — ' . $name, 'email'=>(string)$row['email']
            ];
        }
    }

    if (appointment_table_exists($conn, 'departments') && appointment_table_exists($conn, 'members')) {
        $rows = appointment_rows($conn, "SELECT d.id AS department_id,d.name AS department_name,m.id AS member_id,m.user_id,u.id AS user_id,u.full_name,u.email FROM departments d JOIN members m ON m.id=d.leader JOIN users u ON u.id=m.user_id WHERE COALESCE(d.status,'Active')='Active' AND COALESCE(m.status,'Active')='Active' AND u.email IS NOT NULL AND TRIM(u.email)<>'' AND (u.status IS NULL OR LOWER(u.status) IN ('active','1','enabled')) ORDER BY d.name");
        foreach ($rows as $row) {
            $key = 'department:' . (int)$row['department_id'];
            $name = trim((string)$row['full_name']) ?: 'Department Leader';
            $label = 'Department Leader — ' . (string)$row['department_name'] . ' (' . $name . ')';
            $targets[$key] = [
                'key'=>$key, 'label'=>$label, 'recipient_user_id'=>(int)$row['user_id'],
                'recipient_member_id'=>(int)$row['member_id'], 'department_id'=>(int)$row['department_id'],
                'recipient_label'=>$label, 'email'=>(string)$row['email']
            ];
        }
    }
    return array_values($targets);
}

function appointment_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function appointment_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $key => &$value) $bind[] = &$value;
        unset($value);
        if (!call_user_func_array([$stmt, 'bind_param'], $bind)) { $stmt->close(); return []; }
    }
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function appointment_is_global_reviewer(): bool
{
    return in_array(strtolower(trim((string)($_SESSION['role'] ?? ''))), [
        'admin','administrator','super admin','super_admin'
    ], true);
}

function appointment_can_review(mysqli $conn, array $request): bool
{
    if (appointment_is_global_reviewer()) return true;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $memberId = function_exists('current_member_id') ? current_member_id() : 0;
    return ($userId > 0 && (int)$request['recipient_user_id'] === $userId)
        || ($memberId > 0 && (int)($request['recipient_member_id'] ?? 0) === $memberId);
}

function appointment_is_department_leader(mysqli $conn): bool
{
    $memberId = function_exists('current_member_id') ? current_member_id() : 0;
    if ($memberId <= 0 || !appointment_table_exists($conn, 'departments')) return false;
    $stmt = $conn->prepare("SELECT 1 FROM departments WHERE leader=? AND COALESCE(status,'Active')='Active' LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

function appointment_send_email(mysqli $conn, string $to, string $name, string $subject, string $text): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    require_once __DIR__ . '/../config/mail-function.php';
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeText = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#172033;line-height:1.6"><h2>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2><p>Hello ' . $safeName . ',</p><p>' . $safeText . '</p><p>Sign in to the church portal to review or track the appointment request.</p></div>';
    return function_exists('sendSystemEmail') && sendSystemEmail($conn, $to, $name, $subject, $html, $text);
}
