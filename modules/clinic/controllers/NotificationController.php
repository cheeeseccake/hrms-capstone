<?php
require_once __DIR__ . '/../classes/Notification.php';

class NotificationController
{
    private $model;

    public function __construct()
    {
        $this->model = new Notification();
    }

    public function listRecent($limit = 20)
    {
        return $this->model->getRecentAll($limit);
    }

    public function unreadCount($employeeId = null)
    {
        return $this->model->getUnreadCount($employeeId);
    }

    public function markRead($notification_id)
    {
        return $this->model->markAsRead($notification_id);
    }

    public function markAllRead()
    {
        return $this->model->markAllAsRead();
    }

    public function dispatch($notification_id)
    {
        return $this->model->markAsRead($notification_id);
    }
}
