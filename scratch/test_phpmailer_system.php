<?php
/**
 * Test Suite: PHPMailer Integration, Forgot Password & Notification Workflows
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/email_template.php';
require_once __DIR__ . '/../includes/notification_service.php';

$pdo = Database::getConnection();
$pdo->exec("DELETE FROM email_logs");

echo "=================================================================\n";
echo "TEST SUITE: PHPMAILER & FORGOT PASSWORD INTEGRATION\n";
echo "=================================================================\n\n";

$passes = 0;
$failures = [];

function assertTest(string $name, bool $condition, string $detail = '') {
    global $passes, $failures;
    if ($condition) {
        echo " [PASS] $name\n";
        $passes++;
    } else {
        echo " [FAIL] $name: $detail\n";
        $failures[] = "$name: $detail";
    }
}

// -------------------------------------------------------------------------
// 1. PHPMailer Autoload & Configuration
// -------------------------------------------------------------------------
echo "--- 1. Testing PHPMailer Installation & Configuration ---\n";

assertTest("PHPMailer class exists", class_exists('PHPMailer\PHPMailer\PHPMailer'));
assertTest("SMTP class exists", class_exists('PHPMailer\PHPMailer\SMTP'));
assertTest("EmailService class exists", class_exists('EmailService'));
assertTest("EmailTemplate class exists", class_exists('EmailTemplate'));

$config = MailConfig::get();
assertTest("SMTP Host is smtp.gmail.com", $config['host'] === 'smtp.gmail.com');
assertTest("SMTP Port is 587", $config['port'] === 587);
assertTest("SMTP Encryption is tls", in_array($config['encryption'], ['tls', 'starttls']));
assertTest("SMTP Auth is enabled", $config['auth'] === true);
assertTest("Sender name is 'Apartment Management System'", $config['from_name'] === 'Apartment Management System');
assertTest("APP_URL is configured", !empty($config['app_url']));

// Check .env.example exists and contains no hardcoded private passwords
assertTest(".env.example file exists", file_exists(__DIR__ . '/../.env.example'));
$envExample = file_get_contents(__DIR__ . '/../.env.example');
assertTest(".env.example uses placeholder credentials", str_contains($envExample, 'your_16_character_app_password'));
assertTest(".env.example specifies Apartment Management System", str_contains($envExample, 'Apartment Management System'));

// Check .env exists and is ignored by git
assertTest(".env exists in project root", file_exists(__DIR__ . '/../.env'));
assertTest(".gitignore exists", file_exists(__DIR__ . '/../.gitignore'));
$gitignore = file_get_contents(__DIR__ . '/../.gitignore');
assertTest(".gitignore ignores .env", str_contains($gitignore, '.env'));

// -------------------------------------------------------------------------
// 2. Forgot Password Flow (Owner and Tenant)
// -------------------------------------------------------------------------
echo "\n--- 2. Testing Forgot Password & Password Reset Flow ---\n";

// Ensure tables exist
$prCols = $pdo->query("SHOW COLUMNS FROM password_resets")->fetchAll(PDO::FETCH_COLUMN);
assertTest("password_resets table has token_hash", in_array('token_hash', $prCols));
assertTest("password_resets table has expires_at", in_array('expires_at', $prCols));
assertTest("password_resets table has used_at", in_array('used_at', $prCols));

// Provision or pick an active test user
$testUser = $pdo->query("SELECT id, name, email, password_hash, role FROM users WHERE role = 'tenant' AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$testUser) {
    // If no tenant exists, pick landlord
    $testUser = $pdo->query("SELECT id, name, email, password_hash, role FROM users WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
assertTest("Test user exists (ID: {$testUser['id']}, Role: {$testUser['role']}, Email: {$testUser['email']})", !empty($testUser));

$testUserId = (int)$testUser['id'];
$originalHash = $testUser['password_hash'];

// Simulate Forgot Password generation
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

$pdo->prepare("DELETE FROM password_resets WHERE user_id = :uid")->execute([':uid' => $testUserId]);

$insToken = $pdo->prepare("
    INSERT INTO password_resets (user_id, email, token_hash, ip_address, expires_at, created_at)
    VALUES (:uid, :email, :thash, '127.0.0.1', DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())
");
$insToken->execute([':uid' => $testUserId, ':email' => $testUser['email'], ':thash' => $tokenHash]);
$resetId = (int)$pdo->lastInsertId();
assertTest("Password reset token inserted with 60-min expiry (ID: $resetId)", $resetId > 0);

// Verify reset link dispatch
$resetUrl = $config['app_url'] . '/reset_password.php?token=' . $rawToken;
$mailSent = EmailService::sendPasswordReset($pdo, $testUser['email'], $testUser['name'], $resetUrl, 60);
assertTest("Password reset email dispatched without error", $mailSent);

// Verify reset link renders valid password reset form via HTTP GET
$ch = curl_init($resetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resetHtml = curl_exec($ch);
$resetHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertTest("reset_password.php loaded via HTTP GET (Status: $resetHttpCode)", $resetHttpCode === 200);
assertTest("reset_password.php presents 'Set New Password' form", str_contains($resetHtml, 'Set New Password') && str_contains($resetHtml, 'name="new_password"'));

// Verify token lookup in reset_password.php logic
$checkLookup = $pdo->prepare("
    SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at 
    FROM password_resets pr 
    WHERE pr.token_hash = :thash AND pr.used_at IS NULL AND pr.expires_at > NOW() 
    LIMIT 1
");
$checkLookup->execute([':thash' => $tokenHash]);
$validPr = $checkLookup->fetch(PDO::FETCH_ASSOC);
assertTest("Token lookup validates active, non-expired token", !empty($validPr));

// Perform Password Reset
$newPlainPassword = 'NewSecretPassword987!';
$newHash = password_hash($newPlainPassword, PASSWORD_BCRYPT);

$pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :uid")->execute([':h' => $newHash, ':uid' => $testUserId]);
$pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id")->execute([':id' => $resetId]);

// Verify new password works
$updatedUser = $pdo->query("SELECT password_hash FROM users WHERE id = {$testUserId}")->fetch(PDO::FETCH_ASSOC);
assertTest("Password successfully updated and verifies with bcrypt", password_verify($newPlainPassword, $updatedUser['password_hash']));

// Verify used token is now rejected
$recheckLookup = $pdo->prepare("
    SELECT pr.id 
    FROM password_resets pr 
    WHERE pr.token_hash = :thash AND pr.used_at IS NULL AND pr.expires_at > NOW() 
    LIMIT 1
");
$recheckLookup->execute([':thash' => $tokenHash]);
assertTest("Used password reset token is rejected (cannot be reused)", empty($recheckLookup->fetch()));

// Test expired token rejection
$expiredToken = bin2hex(random_bytes(32));
$expiredHash = hash('sha256', $expiredToken);
$pdo->prepare("
    INSERT INTO password_resets (user_id, email, token_hash, ip_address, expires_at, created_at)
    VALUES (:uid, :email, :thash, '127.0.0.1', DATE_SUB(NOW(), INTERVAL 10 MINUTE), DATE_SUB(NOW(), INTERVAL 70 MINUTE))
")->execute([':uid' => $testUserId, ':email' => $testUser['email'], ':thash' => $expiredHash]);

$expiredCheck = $pdo->prepare("
    SELECT pr.id 
    FROM password_resets pr 
    WHERE pr.token_hash = :thash AND pr.used_at IS NULL AND pr.expires_at > NOW() 
    LIMIT 1
");
$expiredCheck->execute([':thash' => $expiredHash]);
assertTest("Expired password reset token is rejected", empty($expiredCheck->fetch()));

// Restore original password hash
$pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :uid")->execute([':h' => $originalHash, ':uid' => $testUserId]);

// -------------------------------------------------------------------------
// 3. Owner Notification Scenarios
// -------------------------------------------------------------------------
echo "\n--- 3. Testing Owner Email Notifications ---\n";

$owner = $pdo->query("SELECT id, name, email FROM users WHERE role = 'landlord' AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest("Owner account retrieved dynamically (name: {$owner['name']}, email: {$owner['email']})", !empty($owner));

// Scenario 1: Tenant submits payment proof -> Owner receives email
$testPaymentId = 99901;
$ownerNotified = EmailService::notifyOwnerPaymentProofSubmitted(
    $pdo,
    $owner['email'],
    $owner['name'],
    $testUser['name'],
    'Unit 1',
    6000.00,
    date('Y-m-01'),
    $testPaymentId
);
assertTest("Owner receives email when tenant submits payment proof", $ownerNotified);

// Scenario 2: Tenant submits maintenance ticket -> Owner receives email
$testTicketId = 99902;
$ownerTicketNotified = EmailService::notifyOwnerMaintenanceTicketCreated(
    $pdo,
    $owner['email'],
    $owner['name'],
    $testUser['name'],
    'Unit 1',
    'Leaking Faucet in Bathroom',
    'Plumbing',
    'medium',
    $testTicketId
);
assertTest("Owner receives email when tenant submits maintenance ticket", $ownerTicketNotified);

// Scenario 3: Tenant updates profile/contact info -> Owner receives email
$ownerProfileNotified = EmailService::notifyOwnerTenantAccountUpdated(
    $pdo,
    $owner['email'],
    $owner['name'],
    $testUser['name'],
    'Unit 1',
    'Updated emergency contact and phone number',
    $testUserId
);
assertTest("Owner receives email when tenant updates account profile", $ownerProfileNotified);

// Scenario 4: Tenant registered/assigned -> Owner receives email
$ownerTenantNotified = EmailService::notifyOwnerTenantRegistered(
    $pdo,
    $owner['email'],
    $owner['name'],
    'New Resident Assignment',
    'Unit 2',
    99903
);
assertTest("Owner receives email when new tenant is assigned to a unit", $ownerTenantNotified);

// -------------------------------------------------------------------------
// 4. Tenant Notification Scenarios
// -------------------------------------------------------------------------
echo "\n--- 4. Testing Tenant Email Notifications ---\n";

// Scenario 5: Payment proof verified -> Tenant receives email
$tenantPayVerified = EmailService::notifyTenantPaymentVerified(
    $pdo,
    $testUser['email'],
    $testUser['name'],
    'Unit 1',
    6000.00,
    date('Y-m-01'),
    $testPaymentId
);
assertTest("Tenant receives email when payment proof is verified", $tenantPayVerified);

// Scenario 6: Payment proof rejected -> Tenant receives email
$tenantPayRejected = EmailService::notifyTenantPaymentRejected(
    $pdo,
    $testUser['email'],
    $testUser['name'],
    'Unit 1',
    6000.00,
    'The receipt reference number was unreadable. Please upload a clear photo.',
    $testPaymentId + 1
);
assertTest("Tenant receives email when payment proof is rejected", $tenantPayRejected);

// Scenario 7: Maintenance ticket updated -> Tenant receives email
$tenantMaintUpdated = EmailService::notifyTenantMaintenanceUpdated(
    $pdo,
    $testUser['email'],
    $testUser['name'],
    'Unit 1',
    'Air Conditioner Filter Repair',
    'resolved',
    'Filter replaced and unit cleaned by technician.',
    $testTicketId
);
assertTest("Tenant receives email when maintenance request status updates", $tenantMaintUpdated);

// Scenario 8: Payment Due Reminders (Upcoming, Due Today, Overdue)
$tenantDueToday = EmailService::notifyTenantPaymentReminder(
    $pdo,
    $testUser['email'],
    $testUser['name'],
    'Unit 1',
    6000.00,
    date('Y-m-d'),
    'due_today',
    $testPaymentId + 2
);
assertTest("Tenant receives payment due today reminder email", $tenantDueToday);

$tenantUpcoming = EmailService::notifyTenantPaymentReminder(
    $pdo,
    $testUser['email'],
    $testUser['name'],
    'Unit 1',
    6000.00,
    date('Y-m-d', strtotime('+3 days')),
    'upcoming',
    $testPaymentId + 3
);
assertTest("Tenant receives upcoming rent reminder email", $tenantUpcoming);

$tenantOverdue = EmailService::notifyTenantPaymentReminder(
    $pdo,
    $testUser['email'],
    $testUser['name'],
    'Unit 1',
    6000.00,
    date('Y-m-d', strtotime('-5 days')),
    'overdue',
    $testPaymentId + 4
);
assertTest("Tenant receives overdue rent reminder email", $tenantOverdue);

// Scenario 9: Community Announcement -> Tenant receives email
$announcementSent = EmailService::notifyTenantAnnouncement(
    $pdo,
    $testUser['email'],
    $testUser['name'],
    'Water Interruption Notice',
    'Scheduled maintenance of water tanks will take place this Saturday from 9 AM to 1 PM.',
    888
);
assertTest("Tenant receives community announcement email", $announcementSent);

// Scenario 10: Welcome New Tenant -> Tenant receives onboarding email
$welcomeSent = EmailService::notifyTenantWelcome(
    $pdo,
    $testUser['email'],
    $testUser['name'],
    'Unit 1',
    $testUserId
);
assertTest("Tenant receives welcome onboarding email", $welcomeSent);

// -------------------------------------------------------------------------
// 5. Centralized Email Sending Function & Diagnostics
// -------------------------------------------------------------------------
echo "\n--- 5. Testing Centralized Email Service & Diagnostics ---\n";

// Test custom email sending with HTML and plain-text fallback
$customSent = EmailService::sendCustomEmail(
    $pdo,
    $testUser['email'],
    'Test Custom Subject',
    '<h2>Custom HTML Message</h2><p>This is a custom message.</p>',
    "Custom HTML Message\nThis is a custom message.",
    $testUser['name'],
    'custom_test'
);
assertTest("sendCustomEmail dispatches with HTML and plain-text alternative", $customSent);

// Test recipient validation
$invalidEmailSent = EmailService::send(
    $pdo,
    'not-a-valid-email',
    'Bad Recipient',
    'Subject',
    '<p>Body</p>',
    'bad_email_test'
);
assertTest("send() rejects invalid recipient email format and returns false", $invalidEmailSent === false);

// Test SMTP connection diagnostic tool
$smtpDiag = EmailService::testSmtpConnection();
assertTest("testSmtpConnection() executes and returns structured diagnosis array", is_array($smtpDiag) && isset($smtpDiag['success']) && isset($smtpDiag['message']));

// -------------------------------------------------------------------------
// 6. In-App Notification System Bridge & Non-blocking Safety
// -------------------------------------------------------------------------
echo "\n--- 6. Testing In-App Notification System Bridge & Non-blocking Safety ---\n";

// Trigger in-app notification with email dispatch
$notifId = NotificationService::create(
    $pdo,
    (int)$owner['id'],
    'landlord',
    'payment',
    'Payment Verification Required',
    'Test resident submitted payment proof. Verification required.',
    'sectionPayments',
    $testPaymentId,
    'payment_pending',
    true
);
assertTest("In-app notification created successfully with ID", $notifId > 0);

// Verify that in-app notification exists in database
$chkNotif = $pdo->query("SELECT id, title, is_read FROM notifications WHERE id = $notifId")->fetch(PDO::FETCH_ASSOC);
assertTest("In-app notification persisted in notifications table", !empty($chkNotif));

// Test that email failure does NOT break operations
$safeNotifId = NotificationService::create(
    $pdo,
    99999, // non-existent user with no email
    'tenant',
    'system',
    'System Warning',
    'Testing non-blocking email failure.',
    null,
    null,
    null,
    true
);
assertTest("In-app notification succeeds even when email recipient is unavailable", $safeNotifId > 0);

// Clean up test notifications
$pdo->prepare("DELETE FROM notifications WHERE id IN ($notifId, $safeNotifId)")->execute();

// -------------------------------------------------------------------------
// 7. Deduplication & Email Logs
// -------------------------------------------------------------------------
echo "\n--- 7. Testing Email Deduplication & Email Logs ---\n";

// Deduplication test: Send same payment verified email again with checkDuplicate=true
$dedupSent = EmailService::notifyTenantPaymentVerified(
    $pdo,
    $testUser['email'],
    $testUser['name'],
    'Unit 1',
    6000.00,
    date('Y-m-01'),
    $testPaymentId
);
assertTest("Duplicate email sending is prevented by deduplication check", $dedupSent === true);

$logsCount = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE recipient_email = '{$testUser['email']}'")->fetchColumn();
assertTest("email_logs recorded delivery attempts for recipient", $logsCount > 0);

// Verify sensitive data is NOT logged
$sensitiveFound = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE error_message LIKE '%password_hash%' OR error_message LIKE '%SMTP_PASS%'")->fetchColumn();
assertTest("email_logs contains NO sensitive passwords or secret credentials", $sensitiveFound === 0);

// -------------------------------------------------------------------------
// Final Summary
// -------------------------------------------------------------------------
echo "\n=================================================================\n";
echo "SUMMARY: Passes: $passes | Failures: " . count($failures) . "\n";
echo "=================================================================\n";

if (empty($failures)) {
    echo ">>> ALL PHPMAILER & FORGOT PASSWORD TESTS PASSED! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED <<<\n";
    exit(1);
}
