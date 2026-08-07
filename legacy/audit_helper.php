<?php
/**
 * audit_helper.php
 * Central helper for writing to the `audit_logs` table.
 * Include this after config.php (needs $conn).
 *
 * Usage:
 *   require_once 'audit_helper.php';
 *   record_audit($conn, [
 *       'module'      => 'watermark',
 *       'action'      => 'WATERMARK_TOGGLE',
 *       'target_type' => 'settings',
 *       'target_id'   => 1,
 *       'description' => 'Disabled watermarking',
 *   ]);
 */

if (!function_exists('record_audit')) {
    function record_audit(mysqli $conn, array $data): bool
    {
        $employee_id = $_SESSION['employee_id'] ?? ($data['user_id'] ?? null);
        $user_name   = $_SESSION['name'] ?? null;
        $department  = $_SESSION['department'] ?? null;

        // Fall back to a DB lookup if the session doesn't carry name/department
        if ($employee_id && (!$user_name || !$department)) {
            $stmt = $conn->prepare("SELECT name, department FROM user WHERE employee_id = ?");
            if ($stmt) {
                $stmt->bind_param("s", $employee_id);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($res) {
                    $user_name  = $user_name ?: $res['name'];
                    $department = $department ?: $res['department'];
                }
            }
        }

        $module      = $data['module'] ?? 'general';
        $action      = $data['action'] ?? 'ACTION';
        $target_type = $data['target_type'] ?? null;
        $target_id   = $data['target_id'] ?? null;
        $description = $data['description'] ?? '';
        $ip          = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $conn->prepare(
            "INSERT INTO audit_logs
                (user_id, user_name, department, module, action, target_type, target_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }
        // 6 strings, 1 int (target_id), 2 strings
        $stmt->bind_param(
            "ssssssiss",
            $employee_id,
            $user_name,
            $department,
            $module,
            $action,
            $target_type,
            $target_id,
            $description,
            $ip
        );
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('notify_users')) {
    /**
     * Insert a dashboard notification. Email/mobile push are stubbed via mail_stub().
     */
    function notify_users(mysqli $conn, array $data): void
    {
        $recipient_id   = $data['recipient_id'] ?? null;
        $recipient_role = $data['recipient_role'] ?? null;
        $type           = $data['type'] ?? 'general';
        $title          = $data['title'] ?? '';
        $message        = $data['message'] ?? '';
        $link           = $data['link'] ?? null;

        $stmt = $conn->prepare(
            "INSERT INTO notifications (recipient_id, recipient_role, type, title, message, link)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("ssssss", $recipient_id, $recipient_role, $type, $title, $message, $link);
            $stmt->execute();
            $stmt->close();
        }

        // Email is a stub in this prototype — wire up PHPMailer/SMTP here for production.
        // mail_stub($recipient_id, $title, $message);
    }
}
