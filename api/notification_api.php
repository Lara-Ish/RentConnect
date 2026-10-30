<?php
/**
 * RentConnect - Centralized Notification API Endpoint
 * Handles fetching user notifications, marking single/all as read, and polling for real-time counts.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/notification_service.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$user = Auth::user();
if (!$user || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Please log in to view or manage notifications.'
    ]);
    exit;
}

$pdo = Database::getConnection();
$userId = (int)$user['id'];

try {
    $rawInput = json_decode(file_get_contents('php://input'), true);
    $input = is_array($rawInput) ? $rawInput : $_REQUEST;
    $action = trim((string)($input['action'] ?? ($requestMethod === 'POST' ? 'mark_read' : 'list')));

    // -------------------------------------------------------------------------
    // 1. Mark All Notifications as Read
    // -------------------------------------------------------------------------
    if ($action === 'mark_all_read') {
        NotificationService::markAllAsRead($pdo, $userId);
        echo json_encode([
            'status' => 'success',
            'message' => 'All notifications marked as read.',
            'unread_count' => 0
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. Mark Single Notification as Read
    // -------------------------------------------------------------------------
    if ($action === 'mark_read') {
        $notificationId = (int)($input['notification_id'] ?? ($input['id'] ?? 0));
        if ($notificationId > 0) {
            NotificationService::markAsRead($pdo, $notificationId, $userId);
        }
        $unreadCount = NotificationService::getUnreadCount($pdo, $userId);
        echo json_encode([
            'status' => 'success',
            'notification_id' => $notificationId,
            'unread_count' => $unreadCount
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 3. List Notifications (with Auto-Sync & Unread Count)
    // -------------------------------------------------------------------------
    // Synchronize latest system events
    NotificationService::syncSystemNotifications($pdo, $user);

    $limit = isset($input['limit']) ? max(1, min(100, (int)$input['limit'])) : 30;
    $notifications = NotificationService::getForUser($pdo, $userId, $limit);
    $unreadCount = NotificationService::getUnreadCount($pdo, $userId);

    echo json_encode([
        'status' => 'success',
        'unread_count' => $unreadCount,
        'count' => count($notifications),
        'notifications' => $notifications
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Notification service error: ' . $e->getMessage()
    ]);
    exit;
}
