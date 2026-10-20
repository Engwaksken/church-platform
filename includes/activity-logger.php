<?php
declare(strict_types=1);

/**
 * Central audit/activity logger.
 *
 * Usage:
 * activity_log($conn, $userId, 'login_success', 'User logged in successfully.', [
 *     'login_method' => 'password',
 * ]);
 */

if (!function_exists('activity_log_ensure_table')) {
    function activity_log_ensure_table(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready === true) {
            return true;
        }

        $sql = "CREATE TABLE IF NOT EXISTS activity_logs (
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
          COLLATE=utf8mb4_unicode_ci";

        $ready = $conn->query($sql) === true;

        if (!$ready) {
            error_log('[activity-logger.php] Unable to create/check activity_logs table: ' . $conn->error);
        }

        return $ready;
    }
}

if (!function_exists('activity_log_client_ip')) {
    function activity_log_client_ip(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }

            // X-Forwarded-For can contain a list. Use the first valid address.
            foreach (array_map('trim', explode(',', $candidate)) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return substr($ip, 0, 45);
                }
            }
        }

        return '';
    }
}

if (!function_exists('activity_log')) {
    function activity_log(
        mysqli $conn,
        ?int $userId,
        string $action,
        string $description,
        array $details = [],
        ?int $targetUserId = null
    ): bool {
        if (!activity_log_ensure_table($conn)) {
            return false;
        }

        $action = trim($action);
        $description = trim($description);

        if ($action === '' || $description === '') {
            return false;
        }

        $action = substr($action, 0, 100);
        $description = substr($description, 0, 500);

        $detailsJson = $details !== []
            ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        if ($detailsJson === false) {
            $detailsJson = null;
        }

        $ipAddress = activity_log_client_ip();
        $userAgent = substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

        $stmt = $conn->prepare(
            'INSERT INTO activity_logs
                (user_id, target_user_id, action, description, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        if (!$stmt) {
            error_log('[activity-logger.php] Prepare failed: ' . $conn->error);
            return false;
        }

        $stmt->bind_param(
            'iisssss',
            $userId,
            $targetUserId,
            $action,
            $description,
            $detailsJson,
            $ipAddress,
            $userAgent
        );

        $ok = $stmt->execute();

        if (!$ok) {
            error_log('[activity-logger.php] Insert failed: ' . $stmt->error);
        }

        $stmt->close();
        return $ok;
    }
}
