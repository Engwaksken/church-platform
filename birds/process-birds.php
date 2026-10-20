<?php
declare(strict_types=1);

ob_start();

require_once '../config/config.php';
require_once '../config/db.php';

$notifyFile = __DIR__ . '/bird-notification-functions.php';
if (file_exists($notifyFile)) {
    require_once $notifyFile;
}

if (function_exists('requireLogin')) {
    requireLogin();
}

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

function jb_json(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $ok,
        'message' => $message
    ], $extra));
    exit;
}

function jb_table(mysqli $conn, string $table): bool
{
    $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $res && $res->num_rows > 0;
}

function jb_col(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $sql = "SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "` LIKE '" . $conn->real_escape_string($column) . "'";
    $res = $conn->query($sql);

    return $cache[$key] = ($res && $res->num_rows > 0);
}

function jb_post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function jb_fetch(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    if ($types !== '') {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    $res = $conn->query($sql);
    if (!$res) {
        throw new Exception($conn->error);
    }

    return $res->fetch_assoc() ?: null;
}

function jb_gen_no(mysqli $conn, string $prefix, string $table, string $column): string
{
    $like = $prefix . '-' . date('Y') . '-%';

    $stmt = $conn->prepare("SELECT `$column` FROM `$table` WHERE `$column` LIKE ? ORDER BY id DESC LIMIT 1");
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('s', $like);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = 1;

    if ($row && preg_match('/(\d+)$/', (string)$row[$column], $m)) {
        $next = (int)$m[1] + 1;
    }

    return $prefix . '-' . date('Y') . '-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
}

function jb_alloc_due_col(mysqli $conn): string
{
    if (jb_col($conn, 'bird_allocations', 'return_due_date')) {
        return 'return_due_date';
    }

    if (jb_col($conn, 'bird_allocations', 'expected_return_date')) {
        return 'expected_return_date';
    }

    return 'return_due_date';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jb_json(false, 'Invalid request method.');
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        jb_json(false, 'Database connection not found.');
    }

    $conn->set_charset('utf8mb4');

    if (!jb_table($conn, 'birds')) {
        jb_json(false, 'Missing birds table.');
    }

    $action = trim((string)jb_post('action'));
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'add_bird') {
        $count = max(1, min(50, (int)jb_post('count', 1)));
        $breed = trim((string)jb_post('breed'));
        $gender = trim((string)jb_post('gender', 'Hen'));
        $color = trim((string)jb_post('color'));
        $weight = (float)jb_post('weight_kg', 0);
        $acquired = trim((string)jb_post('acquired_date', date('Y-m-d')));
        $projectId = (int)jb_post('project_id', 0);
        $notes = trim((string)jb_post('notes'));

        if ($breed === '') {
            jb_json(false, 'Breed is required.');
        }

        $conn->begin_transaction();

        $stmt = $conn->prepare("
            INSERT INTO birds (
                bird_no,
                breed,
                gender,
                color,
                weight_kg,
                acquired_date,
                project_id,
                notes,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Available')
        ");

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        for ($i = 0; $i < $count; $i++) {
            $birdNo = jb_gen_no($conn, 'BRD', 'birds', 'bird_no');
            $pid = $projectId > 0 ? $projectId : null;

            $stmt->bind_param(
                'ssssdsis',
                $birdNo,
                $breed,
                $gender,
                $color,
                $weight,
                $acquired,
                $pid,
                $notes
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
        }

        $stmt->close();
        $conn->commit();

        jb_json(true, $count . ' bird(s) added successfully.');
    }

    if ($action === 'get_bird') {
        $id = (int)jb_post('id', 0);

        $row = jb_fetch($conn, "SELECT * FROM birds WHERE id=? LIMIT 1", 'i', [$id]);

        if (!$row) {
            jb_json(false, 'Bird not found.');
        }

        jb_json(true, 'Bird loaded.', ['bird' => $row]);
    }

    if ($action === 'edit_bird') {
        $id = (int)jb_post('id', 0);
        $breed = trim((string)jb_post('breed'));
        $gender = trim((string)jb_post('gender', 'Hen'));
        $color = trim((string)jb_post('color'));
        $weight = (float)jb_post('weight_kg', 0);
        $status = trim((string)jb_post('status', 'Available'));
        $notes = trim((string)jb_post('notes'));

        if ($id <= 0 || $breed === '') {
            jb_json(false, 'Invalid bird details.');
        }

        $stmt = $conn->prepare("
            UPDATE birds
            SET breed=?,
                gender=?,
                color=?,
                weight_kg=?,
                status=?,
                notes=?
            WHERE id=?
        ");

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('sssdssi', $breed, $gender, $color, $weight, $status, $notes, $id);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $stmt->close();

        jb_json(true, 'Bird updated successfully.');
    }

    if ($action === 'delete_bird') {
        $id = (int)jb_post('id', 0);

        $bird = jb_fetch($conn, "SELECT id, status FROM birds WHERE id=? LIMIT 1", 'i', [$id]);

        if (!$bird) {
            jb_json(false, 'Bird not found.');
        }

        if (($bird['status'] ?? '') === 'Allocated') {
            jb_json(false, 'Cannot delete a bird that is currently allocated.');
        }

        $stmt = $conn->prepare("DELETE FROM birds WHERE id=?");
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $stmt->close();

        jb_json(true, 'Bird deleted successfully.');
    }

    if ($action === 'allocate_bird') {
        if (!jb_table($conn, 'bird_allocations')) {
            jb_json(false, 'Missing bird_allocations table.');
        }

        $birdId = (int)jb_post('bird_id', 0);
        $youthId = (int)jb_post('youth_id', 0);
        $allocatedOn = trim((string)jb_post('allocation_date', date('Y-m-d')));
        $dueDate = trim((string)jb_post('return_due_date', date('Y-m-d', strtotime('+1 year'))));
        $notes = trim((string)jb_post('remarks'));

        if ($birdId <= 0 || $youthId <= 0) {
            jb_json(false, 'Please select bird and youth.');
        }

        $bird = jb_fetch($conn, "SELECT * FROM birds WHERE id=? AND status='Available' LIMIT 1", 'i', [$birdId]);

        if (!$bird) {
            jb_json(false, 'Selected bird is not available.');
        }

        $existing = jb_fetch(
            $conn,
            "SELECT COUNT(*) total FROM bird_allocations WHERE youth_id=? AND status IN('Active','Overdue')",
            'i',
            [$youthId]
        );

        if ((int)($existing['total'] ?? 0) > 0) {
            jb_json(false, 'This youth already has an active bird allocation.');
        }

        $dueCol = jb_alloc_due_col($conn);

        $prev = jb_fetch($conn, "SELECT COUNT(*) total FROM bird_allocations WHERE bird_id=?", 'i', [$birdId]);
        $cycle = (int)($prev['total'] ?? 0) + 1;

        $allocNo = jb_col($conn, 'bird_allocations', 'allocation_no')
            ? jb_gen_no($conn, 'ALLOC', 'bird_allocations', 'allocation_no')
            : '';

        $conn->begin_transaction();

        $columns = [
            'bird_id',
            'youth_id',
            'allocated_on',
            $dueCol,
            'status',
            'cycle_number',
            'notes',
            'created_at'
        ];

        $valuesSql = "?, ?, ?, ?, 'Active', ?, ?, NOW()";
        $types = 'iissis';
        $params = [$birdId, $youthId, $allocatedOn, $dueDate, $cycle, $notes];

        if (jb_col($conn, 'bird_allocations', 'allocation_no')) {
            array_unshift($columns, 'allocation_no');
            $valuesSql = "?, " . $valuesSql;
            $types = 's' . $types;
            array_unshift($params, $allocNo);
        }

        $sql = "
            INSERT INTO bird_allocations (`" . implode('`,`', $columns) . "`)
            VALUES ($valuesSql)
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $allocationId = (int)$conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("UPDATE birds SET status='Allocated' WHERE id=?");
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('i', $birdId);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $stmt->close();

        $conn->commit();

        $notify = ['email' => false, 'sms' => false];

        if ($allocationId > 0 && function_exists('bird_notify_allocation')) {
            $notify = bird_notify_allocation($conn, $allocationId);
        }

        $notifyMsg = [];

        if (!empty($notify['email'])) {
            $notifyMsg[] = 'email sent';
        }

        if (!empty($notify['sms'])) {
            $notifyMsg[] = 'SMS sent';
        }

        $suffix = $notifyMsg ? ' Notification: ' . implode(', ', $notifyMsg) . '.' : '';

        jb_json(true, 'Bird allocated successfully. Allocation No: ' . ($allocNo ?: $allocationId) . '.' . $suffix);
    }

    /* -- allocate_bird_bulk: multiple bird?youth pairs in one request -- */
    if ($action === 'allocate_bird_bulk') {
        if (!jb_table($conn, 'bird_allocations')) {
            jb_json(false, 'Missing bird_allocations table.');
        }

        $birdIds  = array_values(array_filter(array_map('intval', (array)($_POST['bird_ids']  ?? [])), fn($v) => $v > 0));
        $youthIds = array_values(array_filter(array_map('intval', (array)($_POST['youth_ids'] ?? [])), fn($v) => $v > 0));

        if (empty($birdIds) || count($birdIds) !== count($youthIds)) {
            jb_json(false, 'Each bird must be paired with exactly one youth member.');
        }

        /* Check for duplicate birds or youth within this request */
        if (count($birdIds)  !== count(array_unique($birdIds)))  jb_json(false, 'Duplicate birds selected. Each bird can only be allocated once.');
        if (count($youthIds) !== count(array_unique($youthIds))) jb_json(false, 'Duplicate youth selected. Each youth can only receive one bird.');

        $allocatedOn = trim((string)($_POST['allocation_date']  ?? date('Y-m-d'))) ?: date('Y-m-d');
        $dueDate     = trim((string)($_POST['return_due_date']  ?? date('Y-m-d', strtotime('+1 year')))) ?: date('Y-m-d', strtotime('+1 year'));
        $notes       = trim((string)($_POST['remarks'] ?? ''));
        $dueCol      = jb_alloc_due_col($conn);

        /* Pre-validate all pairs before touching the DB */
        $pairs = [];
        foreach ($birdIds as $i => $birdId) {
            $youthId = $youthIds[$i];

            $bird = jb_fetch($conn, "SELECT id, bird_no, breed, status FROM birds WHERE id=? AND status='Available' LIMIT 1", 'i', [$birdId]);
            if (!$bird) jb_json(false, "Bird ID {$birdId} is not available or does not exist.");

            $existingYouth = jb_fetch($conn,
                "SELECT COUNT(*) total FROM bird_allocations WHERE youth_id=? AND status IN('Active','Overdue')", 'i', [$youthId]);
            if ((int)($existingYouth['total'] ?? 0) > 0) {
                jb_json(false, "Youth ID {$youthId} already has an active bird allocation.");
            }

            $prev  = jb_fetch($conn, "SELECT COUNT(*) total FROM bird_allocations WHERE bird_id=?", 'i', [$birdId]);
            $cycle = (int)($prev['total'] ?? 0) + 1;

            $allocNo = jb_col($conn, 'bird_allocations', 'allocation_no')
                ? jb_gen_no($conn, 'ALLOC', 'bird_allocations', 'allocation_no')
                : '';

            $pairs[] = [
                'bird_id'  => $birdId,
                'youth_id' => $youthId,
                'bird_no'  => $bird['bird_no'],
                'cycle'    => $cycle,
                'alloc_no' => $allocNo,
            ];
        }

        /* All valid — run inside a single transaction */
        $conn->begin_transaction();

        $allocatedNos = [];
        $allocationIds = [];

        foreach ($pairs as $pair) {
            $columns   = ['bird_id','youth_id','allocated_on',$dueCol,'status','cycle_number','notes','created_at'];
            $valuesSql = "?, ?, ?, ?, 'Active', ?, ?, NOW()";
            $types     = 'iissis';
            $params    = [$pair['bird_id'], $pair['youth_id'], $allocatedOn, $dueDate, $pair['cycle'], $notes];

            if ($pair['alloc_no'] !== '') {
                array_unshift($columns, 'allocation_no');
                $valuesSql = "?, " . $valuesSql;
                $types     = 's' . $types;
                array_unshift($params, $pair['alloc_no']);
            }

            $sql  = "INSERT INTO bird_allocations (`" . implode('`,`', $columns) . "`) VALUES ($valuesSql)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $allocationIds[] = (int)$conn->insert_id;
            $stmt->close();

            /* mark bird as Allocated */
            $upd = $conn->prepare("UPDATE birds SET status='Allocated' WHERE id=?");
            if (!$upd) throw new Exception($conn->error);
            $upd->bind_param('i', $pair['bird_id']);
            if (!$upd->execute()) throw new Exception($upd->error);
            $upd->close();

            $allocatedNos[] = $pair['alloc_no'] ?: $pair['bird_no'];
        }

        $conn->commit();

        /* Send notifications for each allocation */
        $notifyBits = [];
        foreach ($allocationIds as $aid) {
            if (function_exists('bird_notify_allocation')) {
                $n = bird_notify_allocation($conn, $aid);
                if (!empty($n['email'])) $notifyBits[] = 'email';
                if (!empty($n['sms']))   $notifyBits[] = 'sms';
            }
        }

        $count  = count($pairs);
        $msg    = $count === 1
            ? 'Bird allocated successfully. Allocation No: ' . $allocatedNos[0] . '.'
            : "{$count} birds allocated successfully. Nos: " . implode(', ', $allocatedNos) . '.';
        if ($notifyBits) $msg .= ' Notifications sent: ' . implode(', ', array_unique($notifyBits)) . '.';

        jb_json(true, $msg, ['count' => $count, 'allocation_nos' => $allocatedNos]);
    }

    if ($action === 'return_bird') {
        if (!jb_table($conn, 'bird_allocations')) {
            jb_json(false, 'Missing bird_allocations table.');
        }

        $allocId = (int)jb_post('alloc_id', 0);
        $returnDate = trim((string)jb_post('return_date', date('Y-m-d')));
        $chickCount = max(0, (int)jb_post('chick_count', 0));
        $condition = trim((string)jb_post('chick_condition', 'Good'));
        $nextYouth = (int)jb_post('next_youth_id', 0);
        $notes = trim((string)jb_post('notes'));

        if ($allocId <= 0) {
            jb_json(false, 'Please select allocation.');
        }

        $alloc = jb_fetch(
            $conn,
            "
            SELECT ba.*, b.bird_no, b.id AS bird_id, b.breed
            FROM bird_allocations ba
            JOIN birds b ON b.id = ba.bird_id
            WHERE ba.id = ?
              AND ba.status IN('Active','Overdue')
            LIMIT 1
            ",
            'i',
            [$allocId]
        );

        if (!$alloc) {
            jb_json(false, 'Active allocation not found.');
        }

        $dueCol = jb_alloc_due_col($conn);

        $conn->begin_transaction();

        $sets = ["status='Completed'"];

        if (jb_col($conn, 'bird_allocations', 'actual_return_date')) {
            $sets[] = "actual_return_date='" . $conn->real_escape_string($returnDate) . "'";
        }

        if (jb_col($conn, 'bird_allocations', 'chick_returned')) {
            $sets[] = "chick_returned=" . ($chickCount > 0 ? 1 : 0);
        }

        if (jb_col($conn, 'bird_allocations', 'chick_count')) {
            $sets[] = "chick_count=" . $chickCount;
        }

        if (jb_col($conn, 'bird_allocations', 'chick_condition')) {
            $sets[] = "chick_condition='" . $conn->real_escape_string($condition) . "'";
        }

        if (jb_col($conn, 'bird_allocations', 'notes') && $notes !== '') {
            $sets[] = "notes=CONCAT(COALESCE(notes,''),' | Return: " . $conn->real_escape_string($notes) . "')";
        }

        $updateSql = "UPDATE bird_allocations SET " . implode(', ', $sets) . " WHERE id=" . $allocId;

        if (!$conn->query($updateSql)) {
            throw new Exception($conn->error);
        }

        $birdId = (int)$alloc['bird_id'];

        $stmt = $conn->prepare("UPDATE birds SET status='Returned' WHERE id=?");
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('i', $birdId);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $stmt->close();

        $newBirdNo = '';
        $newAllocId = null;

        if ($chickCount > 0 && $nextYouth > 0) {
            $existing = jb_fetch(
                $conn,
                "SELECT COUNT(*) total FROM bird_allocations WHERE youth_id=? AND status IN('Active','Overdue')",
                'i',
                [$nextYouth]
            );

            if ((int)($existing['total'] ?? 0) > 0) {
                throw new Exception('Selected next youth already has an active allocation.');
            }

            $project = jb_table($conn, 'projects')
                ? jb_fetch($conn, "SELECT id FROM projects WHERE type='Birds' AND status='Active' ORDER BY id DESC LIMIT 1")
                : null;

            $projectId = $project ? (int)$project['id'] : null;

            $newBirdNo = jb_gen_no($conn, 'BRD', 'birds', 'bird_no');
            $breed = (string)($alloc['breed'] ?? 'Local Hen');
            $color = 'Chick from ' . (string)($alloc['bird_no'] ?? 'bird');
            $newNotes = 'Chick from returned bird ' . (string)($alloc['bird_no'] ?? '') . '. ' . $notes;

            $stmt = $conn->prepare("
                INSERT INTO birds (
                    bird_no,
                    breed,
                    gender,
                    color,
                    weight_kg,
                    acquired_date,
                    project_id,
                    notes,
                    status
                ) VALUES (?, ?, 'Hen', ?, 0, ?, ?, ?, 'Allocated')
            ");

            if (!$stmt) {
                throw new Exception($conn->error);
            }

            $stmt->bind_param(
                'ssssis',
                $newBirdNo,
                $breed,
                $color,
                $returnDate,
                $projectId,
                $newNotes
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $newBirdId = (int)$conn->insert_id;
            $stmt->close();

            $newDue = date('Y-m-d', strtotime($returnDate . ' +1 year'));
            $newCycle = 1;
            $newAllocNotes = 'Auto-allocated from chick return of ' . (string)($alloc['bird_no'] ?? 'bird');

            $newAllocNo = jb_col($conn, 'bird_allocations', 'allocation_no')
                ? jb_gen_no($conn, 'ALLOC', 'bird_allocations', 'allocation_no')
                : '';

            $columns = [
                'bird_id',
                'youth_id',
                'allocated_on',
                $dueCol,
                'status',
                'cycle_number',
                'notes',
                'created_at'
            ];

            $valuesSql = "?, ?, ?, ?, 'Active', ?, ?, NOW()";
            $types = 'iissis';
            $params = [$newBirdId, $nextYouth, $returnDate, $newDue, $newCycle, $newAllocNotes];

            if (jb_col($conn, 'bird_allocations', 'allocation_no')) {
                array_unshift($columns, 'allocation_no');
                $valuesSql = "?, " . $valuesSql;
                $types = 's' . $types;
                array_unshift($params, $newAllocNo);
            }

            $sql = "
                INSERT INTO bird_allocations (`" . implode('`,`', $columns) . "`)
                VALUES ($valuesSql)
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception($conn->error);
            }

            $stmt->bind_param($types, ...$params);

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $newAllocId = (int)$conn->insert_id;
            $stmt->close();

            if (jb_col($conn, 'bird_allocations', 'new_bird_no')) {
                $stmt = $conn->prepare("UPDATE bird_allocations SET new_bird_no=? WHERE id=?");
                if (!$stmt) {
                    throw new Exception($conn->error);
                }

                $stmt->bind_param('si', $newBirdNo, $allocId);

                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }

                $stmt->close();
            }
        }

        if (jb_table($conn, 'chick_returns')) {
            $cols = [];
            $vals = [];
            $types = '';

            $map = [
                'allocation_id' => ['i', $allocId],
                'return_date' => ['s', $returnDate],
                'chick_count' => ['i', $chickCount],
                'chick_condition' => ['s', $condition],
                'new_bird_no' => ['s', $newBirdNo],
                'next_youth_id' => ['i', $nextYouth > 0 ? $nextYouth : null],
                'notes' => ['s', $notes],
                'received_by' => ['i', $userId],
                'next_allocation_id' => ['i', $newAllocId],
            ];

            foreach ($map as $col => $item) {
                if (jb_col($conn, 'chick_returns', $col)) {
                    $cols[] = $col;
                    $types .= $item[0];
                    $vals[] = $item[1];
                }
            }

            if ($cols) {
                $placeholders = implode(',', array_fill(0, count($cols), '?'));

                $sql = "INSERT INTO chick_returns (`" . implode('`,`', $cols) . "`) VALUES ($placeholders)";
                $stmt = $conn->prepare($sql);

                if ($stmt) {
                    $stmt->bind_param($types, ...$vals);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        $conn->commit();

        $returnNotify = ['email' => false, 'sms' => false];

        if (function_exists('bird_notify_return')) {
            $returnNotify = bird_notify_return($conn, $allocId);
        }

        $newAllocNotify = ['email' => false, 'sms' => false];

        if (!empty($newAllocId) && function_exists('bird_notify_allocation')) {
            $newAllocNotify = bird_notify_allocation($conn, (int)$newAllocId);
        }

        $notifyBits = [];

        if (!empty($returnNotify['email'])) {
            $notifyBits[] = 'return email sent';
        }

        if (!empty($returnNotify['sms'])) {
            $notifyBits[] = 'return SMS sent';
        }

        if (!empty($newAllocNotify['email'])) {
            $notifyBits[] = 'new allocation email sent';
        }

        if (!empty($newAllocNotify['sms'])) {
            $notifyBits[] = 'new allocation SMS sent';
        }

        $msg = 'Return recorded successfully.';

        if ($newBirdNo !== '') {
            $msg .= ' New bird ' . $newBirdNo . ' was allocated to the next youth.';
        }

        if ($notifyBits) {
            $msg .= ' Notifications: ' . implode(', ', $notifyBits) . '.';
        }

        jb_json(true, $msg);
    }

    jb_json(false, 'Invalid request action.');

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        @mysqli_rollback($conn);
    }

    jb_json(false, 'Server error: ' . $e->getMessage());
}