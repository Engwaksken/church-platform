<?php
declare(strict_types=1);



if (!function_exists('bird_nf_table_exists')) {
    function bird_nf_table_exists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        if (!$stmt) return false;
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0) > 0;
    }
}

if (!function_exists('bird_nf_col_exists')) {
    function bird_nf_col_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        if (!$stmt) return $cache[$key] = false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
    }
}

if (!function_exists('bird_nf_due_col')) {
    function bird_nf_due_col(mysqli $conn): string
    {
        if (bird_nf_col_exists($conn, 'bird_allocations', 'return_due_date')) return 'return_due_date';
        if (bird_nf_col_exists($conn, 'bird_allocations', 'expected_return_date')) return 'expected_return_date';
        return 'return_due_date';
    }
}

if (!function_exists('bird_nf_create_log_table')) {
    function bird_nf_create_log_table(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS bird_notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            allocation_id INT NULL,
            youth_id INT NULL,
            member_id INT NULL,
            notification_type VARCHAR(50) NOT NULL,
            channel VARCHAR(20) NOT NULL,
            recipient VARCHAR(190) NULL,
            subject VARCHAR(190) NULL,
            message TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            error_message TEXT NULL,
            sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_allocation_type_channel (allocation_id, notification_type, channel),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('bird_nf_log')) {
    function bird_nf_log(mysqli $conn, ?int $allocationId, ?int $youthId, ?int $memberId, string $type, string $channel, string $recipient, string $subject, string $message, bool $sent, string $error = ''): void
    {
        try {
            bird_nf_create_log_table($conn);
            $status = $sent ? 'sent' : 'failed';
            $sentAt = $sent ? date('Y-m-d H:i:s') : null;
            $stmt = $conn->prepare("INSERT INTO bird_notification_logs (allocation_id, youth_id, member_id, notification_type, channel, recipient, subject, message, status, error_message, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) return;
            $stmt->bind_param('iiissssssss', $allocationId, $youthId, $memberId, $type, $channel, $recipient, $subject, $message, $status, $error, $sentAt);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Bird notification log failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('bird_nf_sent_recently')) {
    function bird_nf_sent_recently(mysqli $conn, int $allocationId, string $type, string $channel, int $days = 6): bool
    {
        if (!bird_nf_table_exists($conn, 'bird_notification_logs')) return false;
        $stmt = $conn->prepare("SELECT id FROM bird_notification_logs WHERE allocation_id = ? AND notification_type = ? AND channel = ? AND status = 'sent' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('issi', $allocationId, $type, $channel, $days);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('bird_nf_allocation')) {
    function bird_nf_allocation(mysqli $conn, int $allocationId): ?array
    {
        if ($allocationId <= 0 || !bird_nf_table_exists($conn, 'bird_allocations')) return null;
        $dueCol = bird_nf_due_col($conn);
        $actualExpr = bird_nf_col_exists($conn, 'bird_allocations', 'actual_return_date') ? 'ba.actual_return_date' : "NULL";
        $sql = "SELECT ba.*, ba.`$dueCol` AS due_date, $actualExpr AS actual_return_date,
                       b.bird_no, b.breed, b.gender AS bird_gender, b.color AS bird_color,
                       y.id AS youth_id, y.youth_no, y.member_id,
                       m.full_name, m.email, m.phone
                FROM bird_allocations ba
                JOIN birds b ON b.id = ba.bird_id
                JOIN youth y ON y.id = ba.youth_id
                JOIN members m ON m.id = y.member_id
                WHERE ba.id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $allocationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('bird_nf_send_email')) {
    function bird_nf_send_email(mysqli $conn, string $email, string $name, string $subject, string $html, string $text = ''): bool
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        try {
            if (!function_exists('sendSystemEmail')) {
                $mailFile = dirname(__DIR__) . '/config/mail-function.php';
                if (file_exists($mailFile)) require_once $mailFile;
            }
            if (function_exists('sendSystemEmail')) {
                return sendSystemEmail($conn, $email, $name ?: 'Youth Member', $subject, $html, $text);
            }
        } catch (Throwable $e) {
            error_log('Bird email failed: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('bird_nf_sms_settings')) {
    function bird_nf_sms_settings(mysqli $conn): ?array
    {
        $tables = ['sms_api_settings', 'sms_settings'];
        foreach ($tables as $tbl) {
            if (!bird_nf_table_exists($conn, $tbl)) continue;
            $where = bird_nf_col_exists($conn, $tbl, 'is_active') ? ' WHERE is_active = 1 ' : '';
            $res = $conn->query("SELECT * FROM `$tbl` $where ORDER BY id DESC LIMIT 1");
            if ($res && ($row = $res->fetch_assoc())) return $row;
        }
        return null;
    }
}

if (!function_exists('bird_nf_send_sms')) {
    function bird_nf_send_sms(mysqli $conn, string $phone, string $message): bool
    {
        $phone = trim($phone);
        if ($phone === '') return false;

        try {
            if (function_exists('sendSmsMessage')) {
                return (bool)sendSmsMessage($conn, $phone, $message);
            }

            $settings = bird_nf_sms_settings($conn);
            if (!$settings) return false;

            $url = (string)($settings['api_url'] ?? $settings['endpoint_url'] ?? $settings['endpoint'] ?? '');
            if ($url === '') return false;

            $method = strtoupper((string)($settings['method'] ?? 'POST'));
            $phoneParam = (string)($settings['phone_param'] ?? 'phone');
            $messageParam = (string)($settings['message_param'] ?? 'message');
            $apiKey = (string)($settings['api_key'] ?? '');
            $senderId = (string)($settings['sender_id'] ?? '');
            $token = (string)($settings['token'] ?? '');

            $payload = [
                $phoneParam => $phone,
                $messageParam => $message,
            ];
            if ($apiKey !== '') $payload['api_key'] = $apiKey;
            if ($senderId !== '') $payload['sender_id'] = $senderId;
            if ($token !== '') $payload['token'] = $token;

            $ch = curl_init();
            if ($method === 'GET') {
                $sep = str_contains($url, '?') ? '&' : '?';
                curl_setopt($ch, CURLOPT_URL, $url . $sep . http_build_query($payload));
            } else {
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($response === false || $err !== '') {
                error_log('Bird SMS failed: ' . $err);
                return false;
            }
            return $http >= 200 && $http < 300;
        } catch (Throwable $e) {
            error_log('Bird SMS failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bird_nf_html_template')) {
    function bird_nf_html_template(string $title, string $name, string $body, array $rows = []): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($name ?: 'Youth Member', ENT_QUOTES, 'UTF-8');
        $table = '';
        if ($rows) {
            $table .= "<table style='width:100%;border-collapse:collapse;margin:18px 0;'>";
            foreach ($rows as $k => $v) {
                $table .= "<tr><td style='padding:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:bold;width:40%;'>" . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . "</td><td style='padding:10px;border:1px solid #e5e7eb;'>" . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . "</td></tr>";
            }
            $table .= "</table>";
        }
        return "<div style='font-family:Arial,sans-serif;background:#f6f7fb;padding:24px;'><div style='max-width:650px;margin:auto;background:#fff;border-radius:12px;padding:24px;border:1px solid #e5e7eb;'><h2 style='color:#4a1e8f;margin-top:0;'>$safeTitle</h2><p>Dear <strong>$safeName</strong>,</p><p>$body</p>$table<p style='margin-bottom:0;'>Regards,<br><strong>Rongoro COU Church</strong></p></div></div>";
    }
}

if (!function_exists('bird_notify_allocation')) {
    function bird_notify_allocation(mysqli $conn, int $allocationId): array
    {
        $a = bird_nf_allocation($conn, $allocationId);
        if (!$a) return ['email' => false, 'sms' => false];
        $name = trim((string)($a['full_name'] ?? ''));
        $subject = 'Bird Allocation Confirmation';
        $rows = [
            'Bird No' => $a['bird_no'] ?? '',
            'Breed' => $a['breed'] ?? '',
            'Allocation Date' => $a['allocation_date'] ?? '',
            'Expected Return Date' => $a['due_date'] ?? '',
            'Allocation No' => $a['allocation_no'] ?? '',
        ];
        $html = bird_nf_html_template($subject, $name, 'This confirms that a bird has been allocated to you under the Birds Empowerment Program.', $rows);
        $sms = "Rongoro COU: Bird " . ($a['bird_no'] ?? '') . " has been allocated to you. Expected return date: " . ($a['due_date'] ?? '') . ".";
        $emailSent = bird_nf_send_email($conn, (string)($a['email'] ?? ''), $name, $subject, $html);
        $smsSent = bird_nf_send_sms($conn, (string)($a['phone'] ?? ''), $sms);
        bird_nf_log($conn, (int)$a['id'], (int)$a['youth_id'], (int)$a['member_id'], 'allocation_confirmation', 'email', (string)($a['email'] ?? ''), $subject, strip_tags($html), $emailSent);
        bird_nf_log($conn, (int)$a['id'], (int)$a['youth_id'], (int)$a['member_id'], 'allocation_confirmation', 'sms', (string)($a['phone'] ?? ''), $subject, $sms, $smsSent);
        return ['email' => $emailSent, 'sms' => $smsSent];
    }
}

if (!function_exists('bird_notify_return')) {
    function bird_notify_return(mysqli $conn, int $allocationId): array
    {
        $a = bird_nf_allocation($conn, $allocationId);
        if (!$a) return ['email' => false, 'sms' => false];
        $name = trim((string)($a['full_name'] ?? ''));
        $subject = 'Bird Return Confirmation';
        $returnDate = (string)($a['actual_return_date'] ?? date('Y-m-d'));
        $rows = [
            'Bird No' => $a['bird_no'] ?? '',
            'Breed' => $a['breed'] ?? '',
            'Returned On' => $returnDate,
            'Allocation No' => $a['allocation_no'] ?? '',
        ];
        $html = bird_nf_html_template($subject, $name, 'This confirms that your bird return has been recorded successfully. Thank you for supporting the rotation program.', $rows);
        $sms = "Rongoro COU: Thank you. Your return for bird " . ($a['bird_no'] ?? '') . " has been recorded on " . $returnDate . ".";
        $emailSent = bird_nf_send_email($conn, (string)($a['email'] ?? ''), $name, $subject, $html);
        $smsSent = bird_nf_send_sms($conn, (string)($a['phone'] ?? ''), $sms);
        bird_nf_log($conn, (int)$a['id'], (int)$a['youth_id'], (int)$a['member_id'], 'return_confirmation', 'email', (string)($a['email'] ?? ''), $subject, strip_tags($html), $emailSent);
        bird_nf_log($conn, (int)$a['id'], (int)$a['youth_id'], (int)$a['member_id'], 'return_confirmation', 'sms', (string)($a['phone'] ?? ''), $subject, $sms, $smsSent);
        return ['email' => $emailSent, 'sms' => $smsSent];
    }
}

if (!function_exists('bird_send_due_reminder')) {
    function bird_send_due_reminder(mysqli $conn, int $allocationId, bool $force = false): array
    {
        $a = bird_nf_allocation($conn, $allocationId);
        if (!$a) return ['email' => false, 'sms' => false, 'skipped' => true];
        if (!$force) {
            $emailRecent = bird_nf_sent_recently($conn, $allocationId, 'return_due_reminder', 'email', 6);
            $smsRecent = bird_nf_sent_recently($conn, $allocationId, 'return_due_reminder', 'sms', 6);
            if ($emailRecent && $smsRecent) return ['email' => false, 'sms' => false, 'skipped' => true];
        }
        $name = trim((string)($a['full_name'] ?? ''));
        $due = (string)($a['due_date'] ?? '');
        $days = $due ? (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400) : 0;
        $subject = 'Bird Return Reminder';
        $rows = [
            'Bird No' => $a['bird_no'] ?? '',
            'Expected Return Date' => $due,
            'Days Remaining' => $days,
            'Allocation No' => $a['allocation_no'] ?? '',
        ];
        $body = 'This is a reminder that your bird return date is approaching. Please start preparing and inform the church office if you need guidance.';
        $html = bird_nf_html_template($subject, $name, $body, $rows);
        $sms = "Rongoro COU reminder: Your bird " . ($a['bird_no'] ?? '') . " return date is " . $due . ". Please prepare and contact the church office for guidance.";
        $emailSent = false;
        $smsSent = false;
        if ($force || !bird_nf_sent_recently($conn, $allocationId, 'return_due_reminder', 'email', 6)) {
            $emailSent = bird_nf_send_email($conn, (string)($a['email'] ?? ''), $name, $subject, $html);
            bird_nf_log($conn, (int)$a['id'], (int)$a['youth_id'], (int)$a['member_id'], 'return_due_reminder', 'email', (string)($a['email'] ?? ''), $subject, strip_tags($html), $emailSent);
        }
        if ($force || !bird_nf_sent_recently($conn, $allocationId, 'return_due_reminder', 'sms', 6)) {
            $smsSent = bird_nf_send_sms($conn, (string)($a['phone'] ?? ''), $sms);
            bird_nf_log($conn, (int)$a['id'], (int)$a['youth_id'], (int)$a['member_id'], 'return_due_reminder', 'sms', (string)($a['phone'] ?? ''), $subject, $sms, $smsSent);
        }
        return ['email' => $emailSent, 'sms' => $smsSent, 'skipped' => false];
    }
}