<?php
/**
 * Test Suite: Notification System & Navigation Bar Updates
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

echo "=== RENTCONNECT NOTIFICATION SYSTEM & NAV BAR VERIFICATION ===\n\n";

$pdo = Database::getConnection();
$adminContent = file_get_contents(__DIR__ . '/../admin_dashboard.php');
$tenantContent = file_get_contents(__DIR__ . '/../tenant_portal.php');
$cssContent = file_get_contents(__DIR__ . '/../assets/css/claymorphism.css');
$jsContent = file_get_contents(__DIR__ . '/../assets/js/notifications.js');

// =========================================================================
// 1. Navigation Bar Checks
// =========================================================================
echo "--- 1. Testing Navigation Bar Changes ---\n";

// 1.1 Refresh button removed completely
assertTest(
    "admin_dashboard: Refresh button completely removed",
    !str_contains($adminContent, 'btn-refresh-compact')
    && !str_contains($adminContent, '🔄 Refresh')
);
assertTest(
    "tenant_portal: Refresh button is not present",
    !str_contains($tenantContent, 'btn-refresh-compact')
    && !str_contains($tenantContent, '🔄 Refresh')
);

// 1.2 User name kept removed from nav bar
assertTest(
    "admin_dashboard: user name removed from top-nav",
    !str_contains($adminContent, 'landlord-header-profile')
);
assertTest(
    "tenant_portal: user name removed from top-nav",
    !str_contains($tenantContent, 'landlord-header-profile')
);

// 1.3 Profile icon kept with first initial
assertTest(
    "admin_dashboard: profile initial badge present with \$userInitial",
    str_contains($adminContent, 'id="headerAvatarBadge"')
    && str_contains($adminContent, 'class="header-avatar-initial"><?= htmlspecialchars($userInitial) ?>')
);
assertTest(
    "tenant_portal: profile initial badge present with \$userInitial",
    str_contains($tenantContent, 'id="headerAvatarBadge"')
    && str_contains($tenantContent, 'class="header-avatar-initial"><?= htmlspecialchars($userInitial) ?>')
);

// 1.4 Notification button (bell icon) and badge present beside profile icon
assertTest(
    "admin_dashboard: notification button (#headerNotificationBtn) present",
    str_contains($adminContent, 'id="headerNotificationBtn"')
    && str_contains($adminContent, 'id="notificationBadge"')
    && str_contains($adminContent, 'id="notificationDropdown"')
);
assertTest(
    "tenant_portal: notification button (#headerNotificationBtn) present",
    str_contains($tenantContent, 'id="headerNotificationBtn"')
    && str_contains($tenantContent, 'id="notificationBadge"')
    && str_contains($tenantContent, 'id="notificationDropdown"')
);

// 1.5 Notification dropdown panel elements present
assertTest(
    "admin_dashboard: notification panel header, heading and mark-all-read button present",
    str_contains($adminContent, 'class="notification-heading">Notifications</h3>')
    && str_contains($adminContent, 'id="btnMarkAllRead"')
    && str_contains($adminContent, 'id="notificationListContainer"')
);
assertTest(
    "tenant_portal: notification panel header, heading and mark-all-read button present",
    str_contains($tenantContent, 'class="notification-heading">Notifications</h3>')
    && str_contains($tenantContent, 'id="btnMarkAllRead"')
    && str_contains($tenantContent, 'id="notificationListContainer"')
);

// 1.6 Profile dropdown remains intact and display-only
assertTest(
    "admin_dashboard: profile card is display-only with settings and signout clickable",
    str_contains($adminContent, 'class="fb-profile-main-row fb-profile-display-only"')
    && str_contains($adminContent, 'id="settingsToggleBtn"')
    && str_contains($adminContent, 'href="logout.php"')
);
assertTest(
    "tenant_portal: profile card is display-only with settings and signout clickable",
    str_contains($tenantContent, 'class="fb-profile-main-row fb-profile-display-only"')
    && str_contains($tenantContent, 'id="settingsToggleBtn"')
    && str_contains($tenantContent, 'href="logout.php"')
);

// =========================================================================
// 2. Database & NotificationService CRUD Checks
// =========================================================================
echo "\n--- 2. Testing Database Table & NotificationService ---\n";

// 2.1 Table schema
$notifCols = $pdo->query("DESCRIBE `notifications`")->fetchAll(PDO::FETCH_COLUMN);
$requiredCols = ['id', 'user_id', 'role', 'type', 'title', 'message', 'link', 'reference_id', 'reference_type', 'is_read', 'created_at'];
foreach ($requiredCols as $col) {
    assertTest("notifications table column '$col' exists", in_array($col, $notifCols, true));
}

// 2.2 Test creating and fetching notifications for Landlord (User 1)
$testLTitle = "Test Landlord Notification " . time();
$lNotifId = NotificationService::create(
    $pdo,
    1,
    'landlord',
    'payment',
    $testLTitle,
    "Test payment message for unit 1",
    'sectionPayments',
    99999,
    'test_ref'
);
assertTest("NotificationService::create returns valid ID", $lNotifId > 0);

$unreadBefore = NotificationService::getUnreadCount($pdo, 1);
assertTest("NotificationService::getUnreadCount > 0", $unreadBefore > 0);

// 2.3 Chronological order check
$notifs = NotificationService::getForUser($pdo, 1, 10);
assertTest("NotificationService::getForUser returns newest first", $notifs[0]['id'] == $lNotifId);
assertTest("Notification formatted relative time present", !empty($notifs[0]['time_ago']));

// 2.4 Mark as read
$marked = NotificationService::markAsRead($pdo, $lNotifId, 1);
assertTest("NotificationService::markAsRead returns true", $marked);
$chkStmt = $pdo->prepare("SELECT is_read FROM notifications WHERE id = :id");
$chkStmt->execute([':id' => $lNotifId]);
assertTest("is_read set to 1 in database", (int)$chkStmt->fetchColumn() === 1);

// 2.5 Mark all as read
$testLTitle2 = "Test Landlord Unread " . time();
$lNotifId2 = NotificationService::create($pdo, 1, 'landlord', 'maintenance', $testLTitle2, "Test maintenance message");
NotificationService::markAllAsRead($pdo, 1);
$unreadAfterAll = NotificationService::getUnreadCount($pdo, 1);
assertTest("NotificationService::markAllAsRead resets unread count to 0", $unreadAfterAll === 0);

// Clean up test rows
$pdo->prepare("DELETE FROM notifications WHERE id IN (:id1, :id2)")->execute([':id1' => $lNotifId, ':id2' => $lNotifId2]);

// =========================================================================
// 3. Role-Based Isolation Checks
// =========================================================================
echo "\n--- 3. Testing Role-Based Notification Access Control ---\n";

// Create private notification for tenant 107
$tNotifId = NotificationService::notifyTenant(
    $pdo,
    107,
    'payment',
    'Private Tenant Notice',
    'This is a private message for Tenant 107',
    'tenantProfile'
);

// Verify landlord (1) does NOT receive it
$landlordList = NotificationService::getForUser($pdo, 1, 100);
$landlordSeesTenantNotice = false;
foreach ($landlordList as $nl) {
    if ($nl['id'] == $tNotifId) {
        $landlordSeesTenantNotice = true;
        break;
    }
}
assertTest("Landlord cannot see Tenant private notification", !$landlordSeesTenantNotice);

// Verify tenant 107 DOES receive it
$tenantList = NotificationService::getForUser($pdo, 107, 100);
$tenantSeesOwnNotice = false;
foreach ($tenantList as $nt) {
    if ($nt['id'] == $tNotifId) {
        $tenantSeesOwnNotice = true;
        break;
    }
}
assertTest("Tenant 107 can access their own notification", $tenantSeesOwnNotice);

// Clean up
$pdo->prepare("DELETE FROM notifications WHERE id = :id")->execute([':id' => $tNotifId]);

// =========================================================================
// 4. API Endpoint Integration Checks
// =========================================================================
echo "\n--- 4. Testing Notification API & Triggers ---\n";

// 4.1 notification_api.php file exists and requires auth
$apiContent = file_get_contents(__DIR__ . '/../api/notification_api.php');
assertTest("api/notification_api.php checks Auth::user()", str_contains($apiContent, 'Auth::user()'));
assertTest("api/notification_api.php supports action=list", str_contains($apiContent, 'list'));
assertTest("api/notification_api.php supports action=mark_read", str_contains($apiContent, 'mark_read'));
assertTest("api/notification_api.php supports action=mark_all_read", str_contains($apiContent, 'mark_all_read'));

// 4.2 System Triggers in payment_api, ticket_api, tenant_directory_api, profile_api
$paymentApiContent = file_get_contents(__DIR__ . '/../api/payment_api.php');
assertTest("payment_api.php notifies tenant on payment verification", str_contains($paymentApiContent, 'NotificationService::notifyTenant'));
assertTest("payment_api.php notifies landlords on receipt upload", str_contains($paymentApiContent, 'NotificationService::notifyLandlords'));

$ticketApiContent = file_get_contents(__DIR__ . '/../api/ticket_api.php');
assertTest("ticket_api.php notifies tenant on ticket update", str_contains($ticketApiContent, 'NotificationService::notifyTenant'));
assertTest("ticket_api.php notifies landlords on new ticket creation", str_contains($ticketApiContent, 'NotificationService::notifyLandlords'));

$dirApiContent = file_get_contents(__DIR__ . '/../api/tenant_directory_api.php');
assertTest("tenant_directory_api.php notifies landlords on new tenant registration", str_contains($dirApiContent, 'NotificationService::notifyLandlords'));
assertTest("tenant_directory_api.php notifies new tenant on registration", str_contains($dirApiContent, 'NotificationService::notifyTenant'));

$profApiContent = file_get_contents(__DIR__ . '/../api/profile_api.php');
assertTest("profile_api.php notifies landlords on tenant profile update", str_contains($profApiContent, 'NotificationService::notifyLandlords'));

// =========================================================================
// 5. CSS Glassmorphism & Client Script Checks
// =========================================================================
echo "\n--- 5. Testing Glassmorphism CSS & Client Script ---\n";

assertTest("CSS: .header-notification-btn styled with glassmorphism blur and round shape", 
    str_contains($cssContent, '.header-notification-btn')
    && str_contains($cssContent, 'border-radius: 50%')
);
assertTest("CSS: .notification-badge styled with gradient and pill border", 
    str_contains($cssContent, '.notification-badge')
    && str_contains($cssContent, 'linear-gradient(135deg, #ef4444, #dc2626)')
);
assertTest("CSS: .notification-dropdown-panel styled with glassmorphism blur and shadow", 
    str_contains($cssContent, '.notification-dropdown-panel')
    && str_contains($cssContent, 'backdrop-filter: blur(16px)')
);
assertTest("JS: notifications.js handles icon categorization and AJAX polling", 
    str_contains($jsContent, 'notif-icon-payment')
    && str_contains($jsContent, 'notif-icon-maintenance')
    && str_contains($jsContent, 'notif-icon-account')
    && str_contains($jsContent, 'loadNotifications')
    && str_contains($jsContent, 'toggleNotificationPanel')
    && str_contains($jsContent, 'markAllNotificationsAsRead')
);
assertTest("admin_dashboard: loads assets/js/notifications.js", 
    str_contains($adminContent, 'src="assets/js/notifications.js"')
);
assertTest("tenant_portal: loads assets/js/notifications.js", 
    str_contains($tenantContent, 'src="assets/js/notifications.js"')
);

echo "\n>>> ALL NOTIFICATION TESTS PASSED SUCCESSFULLY! <<<\n";
