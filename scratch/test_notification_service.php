<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/notification_service.php';

$pdo = Database::getConnection();

// Test for landlord
$landlordStmt = $pdo->query("SELECT * FROM users WHERE role = 'landlord' LIMIT 1");
$landlord = $landlordStmt->fetch(PDO::FETCH_ASSOC);

NotificationService::syncSystemNotifications($pdo, $landlord);
$landlordNotifs = NotificationService::getForUser($pdo, (int)$landlord['id']);
$landlordUnread = NotificationService::getUnreadCount($pdo, (int)$landlord['id']);

echo "Landlord ID: {$landlord['id']}\n";
echo "Landlord Unread Count: $landlordUnread\n";
echo "Landlord Notifications count: " . count($landlordNotifs) . "\n";
foreach (array_slice($landlordNotifs, 0, 3) as $n) {
    echo "  - [{$n['type']}] {$n['title']} (is_read: {$n['is_read']}, time: {$n['time_ago']})\n";
    echo "    Message: {$n['message']}\n";
}

// Test for tenant (Lara Janelle Lucero ID 107)
$tenantStmt = $pdo->query("SELECT * FROM users WHERE id = 107 LIMIT 1");
$tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

NotificationService::syncSystemNotifications($pdo, $tenant);
$tenantNotifs = NotificationService::getForUser($pdo, (int)$tenant['id']);
$tenantUnread = NotificationService::getUnreadCount($pdo, (int)$tenant['id']);

echo "\nTenant ID: {$tenant['id']}\n";
echo "Tenant Unread Count: $tenantUnread\n";
echo "Tenant Notifications count: " . count($tenantNotifs) . "\n";
foreach (array_slice($tenantNotifs, 0, 3) as $n) {
    echo "  - [{$n['type']}] {$n['title']} (is_read: {$n['is_read']}, time: {$n['time_ago']})\n";
    echo "    Message: {$n['message']}\n";
}
