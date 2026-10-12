<?php
/**
 * Notification Model
 * Handles notification creation and tracking (lc_notifications)
 */

require_once __DIR__ . '/../../../database/db.php';

class Notification
{
    private $conn;
    private $table = 'lc_notifications';

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Create a new notification
     */
    public function create($data)
    {
        try {
            $query = "INSERT INTO `lc_notifications`
                      (employee_id, title, message, type, email, notification_type, is_read, created_at, updated_at)
                      VALUES
                      (:employee_id, :title, :message, :type, :email, :notification_type, :is_read, NOW(), NOW())";

            $stmt = $this->conn->prepare($query);

            $employee_id = $data['employee_id'] ?? null;
            $notification_type = $data['notification_type'] ?? $data['type'] ?? 'SYSTEM';
            $title = $data['title'] ?? '';
            $message = $data['message'] ?? '';
            $email = $data['email'] ?? null;

            $stmt->bindParam(':employee_id', $employee_id, PDO::PARAM_INT);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':message', $message);
            $stmt->bindParam(':type', $notification_type);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':notification_type', $notification_type);
            $isRead = !empty($data['is_read']) ? 1 : 0;
            $stmt->bindParam(':is_read', $isRead, PDO::PARAM_INT);

            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Notification creation error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get notification by ID
     */
    public function getById($notification_id)
    {
        $query = "SELECT * FROM lc_notifications WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $notification_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get unread notifications for a user
     */
    public function getUnread($employeeId)
    {
        $query = "SELECT * FROM lc_notifications WHERE employee_id = :employee_id AND is_read = 0 ORDER BY created_at DESC LIMIT 10";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':employee_id', $employeeId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all notifications for a user
     */
    public function getForUser($employeeId, $limit = 20)
    {
        $query = "SELECT * FROM lc_notifications WHERE employee_id = :employee_id ORDER BY created_at DESC LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':employee_id', $employeeId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($notification_id)
    {
        $query = "UPDATE lc_notifications SET is_read = 1, updated_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $notification_id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Get count of unread notifications
     */
    public function getUnreadCount($employeeId = null)
    {
        if ($employeeId !== null && $employeeId > 0) {
            $query = "SELECT COUNT(*) as count FROM lc_notifications WHERE employee_id = :employee_id AND is_read = 0";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':employee_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $query = "SELECT COUNT(*) as count FROM lc_notifications WHERE is_read = 0";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    }

    /**
     * Get the most recent notifications
     */
    public function getRecentAll($limit = 20)
    {
        $limit = max(1, min((int) $limit, 500));
        $query = "SELECT * FROM lc_notifications ORDER BY created_at DESC LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark every unread notification as read
     */
    public function markAllAsRead()
    {
        $query = "UPDATE lc_notifications SET is_read = 1, updated_at = NOW() WHERE is_read = 0";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute();
    }
}

