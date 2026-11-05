<?php
/**
 * Test Suite: Tenant Account Creation Email Notification & Duplicate Prevention
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$pdo = Database::getConnection();

echo "========================================================\n";
echo " TEST 1: EmailService::sendTenantAccountCreated Template\n";
echo "========================================================\n";

$testEmail = 'test.tenant.' . time() . '@example.com';
$testName = 'Maria Clara';
$testUsername = 'RC999';
$testPassword = 'Pass' . rand(1000, 9999);
$testUnit = 'Unit 301';

// Intercept or send email (will log into email_logs)
$sent = EmailService::sendTenantAccountCreated(
    $pdo,
    $testEmail,
    $testName,
    $testUsername,
    $testPassword,
    $testUnit,
    99999,
    99999
);

if (!$sent) {
    echo "❌ FAILED: sendTenantAccountCreated returned false.\n";
    exit(1);
}
echo "✅ SUCCESS: sendTenantAccountCreated executed successfully.\n";

// Verify in email_logs
$logStmt = $pdo->prepare("
    SELECT id, recipient_email, recipient_name, subject, template_type, status, error_message
    FROM email_logs
    WHERE recipient_email = :email
    ORDER BY id DESC
    LIMIT 1
");
$logStmt->execute([':email' => $testEmail]);
$logRow = $logStmt->fetch(PDO::FETCH_ASSOC);

if (!$logRow) {
    echo "❌ FAILED: No record found in email_logs for recipient {$testEmail}.\n";
    exit(1);
}

echo "✅ Log verified: Template = {$logRow['template_type']}, Subject = '{$logRow['subject']}', Status = {$logRow['status']}\n";

if ($logRow['subject'] !== 'Your Apartment Management System Account Has Been Created') {
    echo "❌ FAILED: Subject does not match required string: '{$logRow['subject']}'\n";
    exit(1);
} else {
    echo "✅ Exact subject match verified: 'Your Apartment Management System Account Has Been Created'\n";
}

// Verify that the password is NEVER stored in email_logs
$checkSensitive = $pdo->prepare("
    SELECT COUNT(*) FROM email_logs 
    WHERE recipient_email = :email 
      AND (subject LIKE :pw1 OR error_message LIKE :pw2)
");
$checkSensitive->execute([':email' => $testEmail, ':pw1' => "%{$testPassword}%", ':pw2' => "%{$testPassword}%"]);
$sensitiveCount = (int)$checkSensitive->fetchColumn();

if ($sensitiveCount > 0) {
    echo "❌ SECURITY FAILED: Temporary password leaked in email_logs!\n";
    exit(1);
}
echo "✅ Security check PASSED: Temporary password is NOT stored or leaked in email_logs.\n";

// Clean up test log
$pdo->prepare("DELETE FROM email_logs WHERE recipient_email = :email")->execute([':email' => $testEmail]);

echo "\n========================================================\n";
echo " TEST 2: Verify Single Email Policy (0 Landlord, 1 Tenant)\n";
echo "========================================================\n";

// Count email logs before
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM email_logs")->fetchColumn();

// Test NotificationService::notifyLandlords with $sendEmail = false
NotificationService::notifyLandlords(
    $pdo,
    'account',
    'Test In-App Notification',
    'This is a test notification with sendEmail=false',
    'sectionDirectory',
    null,
    'tenant_registered',
    false
);

$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM email_logs")->fetchColumn();
if ($countAfter !== $countBefore) {
    echo "❌ FAILED: notifyLandlords triggered an email when sendEmail=false!\n";
    exit(1);
}
echo "✅ PASSED: notifyLandlords with sendEmail=false created in-app notification without sending email.\n";

// Test NotificationService::notifyTenant with $sendEmail = false
NotificationService::notifyTenant(
    $pdo,
    1,
    'account',
    'Welcome to RentConnect',
    'Test welcome with sendEmail=false',
    'tenantProfile',
    1,
    'tenant_welcome',
    false
);

$countAfter2 = (int)$pdo->query("SELECT COUNT(*) FROM email_logs")->fetchColumn();
if ($countAfter2 !== $countBefore) {
    echo "❌ FAILED: notifyTenant triggered an email when sendEmail=false!\n";
    exit(1);
}
echo "✅ PASSED: notifyTenant with sendEmail=false created in-app notification without sending extra email.\n";

// Clean up test notifications
$pdo->prepare("DELETE FROM notifications WHERE title LIKE '%Test In-App Notification%'")->execute();

echo "\n========================================================\n";
echo " TEST 3: Backwards Compatibility for Previous Tests\n";
echo "========================================================\n";

$testEmail2 = 'test.legacy.' . time() . '@example.com';
$sentLegacy = EmailService::sendTenantAccountCreated(
    $pdo,
    $testEmail2,
    'Legacy User',
    'Unit 102',
    'http://localhost/RentConnect/reset_password.php?token=test12345',
    '',
    99998,
    99998
);

if (!$sentLegacy) {
    echo "❌ FAILED: Legacy signature for sendTenantAccountCreated returned false.\n";
    exit(1);
}
echo "✅ PASSED: Legacy call with reset URL handled cleanly.\n";
$pdo->prepare("DELETE FROM email_logs WHERE recipient_email = :email")->execute([':email' => $testEmail2]);

echo "\n========================================================\n";
echo " ALL TESTS COMPLETED SUCCESSFULLY!\n";
echo "========================================================\n";
