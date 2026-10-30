<?php
/**
 * Test Suite: Read and Unread Notification Colors, Styling & Dynamics
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/notification_service.php';

function assertTest(string $description, bool $condition, ?string $extra = null): void
{
    if ($condition) {
        echo " [PASS] $description\n";
    } else {
        echo " [FAIL] $description" . ($extra ? " ($extra)" : "") . "\n";
        exit(1);
    }
}

echo "=== TESTING READ AND UNREAD NOTIFICATION COLORS & STYLING ===\n\n";

$pdo = Database::getConnection();
$cssContent = file_get_contents(__DIR__ . '/../assets/css/claymorphism.css');
$jsContent = file_get_contents(__DIR__ . '/../assets/js/notifications.js');

// --- 1. CSS VISUAL SPECIFICATIONS ---
echo "--- 1. Testing CSS Styling for Read vs Unread ---\n";

// 1.1 Unread background is subtle light purple
assertTest(
    "CSS: .notification-item.unread has subtle light purple background",
    str_contains($cssContent, '.notification-item.unread {')
    && (str_contains($cssContent, 'rgba(243, 232, 255') || str_contains($cssContent, '#f5f3ff') || str_contains($cssContent, 'rgba(238, 242, 255'))
);

// 1.2 Unread title is bold and text is dark
assertTest(
    "CSS: .notification-item.unread .notif-title has bold font-weight and dark color",
    str_contains($cssContent, '.notification-item.unread .notif-title {')
    && str_contains($cssContent, 'font-weight: 800')
    && str_contains($cssContent, '#1e1b4b')
);
assertTest(
    "CSS: .notification-item.unread .notif-message has dark readable color",
    str_contains($cssContent, '.notification-item.unread .notif-message {')
    && str_contains($cssContent, '#1e293b')
);

// 1.3 Small purple dot indicator for unread
assertTest(
    "CSS: .notification-unread-dot has small rounded shape and purple color",
    str_contains($cssContent, '.notification-unread-dot {')
    && str_contains($cssContent, 'border-radius: 50%')
    && (str_contains($cssContent, '#7c3aed') || str_contains($cssContent, '#8b5cf6'))
);

// 1.4 Read notification has white or transparent background
assertTest(
    "CSS: .notification-item.read has transparent / white background",
    str_contains($cssContent, '.notification-item.read,')
    && str_contains($cssContent, 'background: transparent')
);

// 1.5 Read title has normal font weight and muted text
assertTest(
    "CSS: .notification-item.read has normal font weight (500) and muted text",
    str_contains($cssContent, '.notification-item.read .notif-title')
    && str_contains($cssContent, 'font-weight: 500')
    && str_contains($cssContent, '.notification-item.read .notif-message')
    && str_contains($cssContent, '#64748b')
);

// --- 2. JAVASCRIPT DYNAMIC TRANSITIONS ---
echo "\n--- 2. Testing JavaScript Dynamic Classes & DOM Updates ---\n";

// 2.1 Dynamic rendering of unread vs read based on is_read
assertTest(
    "JS: sets class 'unread' or 'read' dynamically based on is_read",
    str_contains($jsContent, 'class="notification-item ${isUnread ? \'unread\' : \'read\'}"')
);

// 2.2 Dot indicator only added for unread
assertTest(
    "JS: renders .notification-unread-dot ONLY when isUnread is true",
    str_contains($jsContent, '${isUnread ? \'<span class="notification-unread-dot"')
);

// 2.3 Instant visual transition on click: remove 'unread', add 'read', remove dot
assertTest(
    "JS: handleNotificationClick immediately removes 'unread' and adds 'read'",
    str_contains($jsContent, "itemEl.classList.remove('unread')")
    && str_contains($jsContent, "itemEl.classList.add('read')")
    && str_contains($jsContent, "dot.remove()")
);

// 2.4 Immediately decrements badge count on click
assertTest(
    "JS: handleNotificationClick decrements notification badge immediately",
    str_contains($jsContent, "let newCount = Math.max(0, currentCount - 1);")
);

// 2.5 Mark all as read updates all unread elements to read
assertTest(
    "JS: markAllNotificationsAsRead updates all items from unread to read",
    str_contains($jsContent, "el.classList.remove('unread')")
    && str_contains($jsContent, "el.classList.add('read')")
);

// --- 3. DATABASE PERSISTENCE FOR OWNER & TENANT ---
echo "\n--- 3. Testing Database State Persistence Across Owner & Tenant ---\n";

// Test Owner
$ownerNotifId = NotificationService::create(
    $pdo,
    1,
    'landlord',
    'payment',
    'Color Test Owner Unread',
    'Message for owner'
);

// Verify starts as is_read = 0
$chkOwner0 = $pdo->query("SELECT is_read FROM notifications WHERE id = $ownerNotifId")->fetchColumn();
assertTest("Owner notification created with is_read = 0 (unread)", (int)$chkOwner0 === 0);

// Mark as read
NotificationService::markAsRead($pdo, $ownerNotifId, 1);
$chkOwner1 = $pdo->query("SELECT is_read FROM notifications WHERE id = $ownerNotifId")->fetchColumn();
assertTest("Owner notification transitioned to is_read = 1 (read)", (int)$chkOwner1 === 1);

// Test Tenant (ID 107)
$tenantNotifId = NotificationService::create(
    $pdo,
    107,
    'tenant',
    'maintenance',
    'Color Test Tenant Unread',
    'Message for tenant'
);

$chkTenant0 = $pdo->query("SELECT is_read FROM notifications WHERE id = $tenantNotifId")->fetchColumn();
assertTest("Tenant notification created with is_read = 0 (unread)", (int)$chkTenant0 === 0);

NotificationService::markAsRead($pdo, $tenantNotifId, 107);
$chkTenant1 = $pdo->query("SELECT is_read FROM notifications WHERE id = $tenantNotifId")->fetchColumn();
assertTest("Tenant notification transitioned to is_read = 1 (read)", (int)$chkTenant1 === 1);

// Verify persistence: syncSystemNotifications does NOT revert read status
$ownerUser = $pdo->query("SELECT * FROM users WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
NotificationService::syncSystemNotifications($pdo, $ownerUser);
$chkOwnerPersist = $pdo->query("SELECT is_read FROM notifications WHERE id = $ownerNotifId")->fetchColumn();
assertTest("Owner notification preserves is_read = 1 across sync", (int)$chkOwnerPersist === 1);

$tenantUser = $pdo->query("SELECT * FROM users WHERE id = 107")->fetch(PDO::FETCH_ASSOC);
NotificationService::syncSystemNotifications($pdo, $tenantUser);
$chkTenantPersist = $pdo->query("SELECT is_read FROM notifications WHERE id = $tenantNotifId")->fetchColumn();
assertTest("Tenant notification preserves is_read = 1 across sync", (int)$chkTenantPersist === 1);

// Clean up
$pdo->prepare("DELETE FROM notifications WHERE id IN ($ownerNotifId, $tenantNotifId)")->execute();

echo "\n>>> ALL READ/UNREAD COLOR & DYNAMIC TESTS PASSED! <<<\n";
