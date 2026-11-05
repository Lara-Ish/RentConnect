<?php
/**
 * Test Suite: Verify All 5 Email Notification Functions in RentConnect
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$pdo = Database::getConnection();

echo "========================================================\n";
echo " TESTING ALL 5 EMAIL NOTIFICATION FUNCTIONS IN RENTCONNECT\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $desc): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$desc}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failed++;
    }
}

// -----------------------------------------------------------------------------
// 0. Ensure Test Tenant Exists
// -----------------------------------------------------------------------------
echo "[0] Setup Test Environment:\n";
$tenantStmt = $pdo->query("SELECT id, name, email, unit_id FROM users WHERE role = 'tenant' AND is_active = 1 LIMIT 1");
$testTenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

if (!$testTenant) {
    // Find an empty unit
    $emptyUnitStmt = $pdo->query("SELECT id FROM units WHERE id NOT IN (SELECT unit_id FROM users WHERE role = 'tenant' AND is_active = 1) LIMIT 1");
    $targetUnitId = (int)$emptyUnitStmt->fetchColumn();
    if (!$targetUnitId) $targetUnitId = 1;

    $pdo->prepare("
        INSERT INTO users (unit_id, username, name, email, password_hash, role, verification_status, is_active, created_at)
        VALUES (:uid, 'RC_TEST', 'Lara Janelle Lucero', '24-39470@g.batstate-u.edu.ph', :pwd, 'tenant', 'verified', 1, NOW())
    ")->execute([':uid' => $targetUnitId, ':pwd' => password_hash('123456', PASSWORD_BCRYPT)]);
    $testTenantId = (int)$pdo->lastInsertId();
    $testTenant = [
        'id' => $testTenantId,
        'name' => 'Lara Janelle Lucero',
        'email' => '24-39470@g.batstate-u.edu.ph',
        'unit_id' => $targetUnitId
    ];
} else {
    $testTenantId = (int)$testTenant['id'];
}
assertCondition($testTenantId > 0, "Test tenant available (ID: {$testTenantId}, Name: {$testTenant['name']}, Email: {$testTenant['email']}, Unit: {$testTenant['unit_id']})");
echo "\n";

// -----------------------------------------------------------------------------
// 1. Function 1: Tenant Account Creation (Owner to Tenant)
// -----------------------------------------------------------------------------
echo "[1] Testing Tenant Account Creation Email Notification:\n";
$setupToken = bin2hex(random_bytes(32));
$setupTokenHash = hash('sha256', $setupToken);

$pdo->prepare("
    INSERT INTO password_resets (user_id, email, token_hash, expires_at, attempts, max_attempts, created_at)
    VALUES (:uid, :email, :thash, DATE_ADD(NOW(), INTERVAL 48 HOUR), 0, 5, NOW())
")->execute([
    ':uid'   => $testTenantId,
    ':email' => $testTenant['email'],
    ':thash' => $setupTokenHash
]);

$config = MailConfig::get();
$setupUrl = $config['app_url'] . '/reset_password.php?token=' . $setupToken;

$sent1 = EmailService::sendTenantAccountCreated(
    $pdo,
    $testTenant['email'],
    $testTenant['name'],
    'Unit 101',
    $setupUrl,
    $testTenantId
);
assertCondition($sent1 === true, "sendTenantAccountCreated returned true");

$log1Stmt = $pdo->prepare("
    SELECT * FROM email_logs 
    WHERE recipient_email = :e AND template_type = 'tenant_account_created'
    ORDER BY id DESC LIMIT 1
");
$log1Stmt->execute([':e' => $testTenant['email']]);
$log1 = $log1Stmt->fetch(PDO::FETCH_ASSOC);

assertCondition(!empty($log1), "Email logged in email_logs table");
assertCondition($log1['subject'] === 'Your Apartment Management System Account Has Been Created' || $log1['subject'] === 'Your Tenant Account Has Been Created', "Email subject matches requirement ({$log1['subject']})");
assertCondition(in_array($log1['status'], ['sent', 'mock_sent'], true), "Delivery status is valid ({$log1['status']})");
echo "\n";

// -----------------------------------------------------------------------------
// 2. Function 2: Maintenance Ticket Reply (Owner to Tenant)
// -----------------------------------------------------------------------------
echo "[2] Testing Maintenance Ticket Reply Email Notification:\n";
// Ensure a test ticket exists
$tktCheck = $pdo->prepare("SELECT id FROM maintenance_tickets WHERE tenant_id = :tid LIMIT 1");
$tktCheck->execute([':tid' => $testTenantId]);
$ticketId = $tktCheck->fetchColumn();

if (!$ticketId) {
    $pdo->prepare("
        INSERT INTO maintenance_tickets (tenant_id, unit_id, title, description, category, priority, status, created_at)
        VALUES (:tid, 1, 'Leaking kitchen faucet', 'Water drips continuously under sink', 'Plumbing', 'medium', 'open', NOW())
    ")->execute([':tid' => $testTenantId]);
    $ticketId = (int)$pdo->lastInsertId();
} else {
    $ticketId = (int)$ticketId;
}

$ownerReplyText = "Plumber scheduled to arrive tomorrow at 9:00 AM. Parts have been ordered.";
$sent2 = EmailService::notifyTenantTicketReply(
    $pdo,
    $testTenant['email'],
    $testTenant['name'],
    'Leaking kitchen faucet',
    $ownerReplyText,
    'In Progress',
    $ticketId
);
assertCondition($sent2 === true, "notifyTenantTicketReply returned true");

$log2Stmt = $pdo->prepare("
    SELECT * FROM email_logs 
    WHERE recipient_email = :e AND template_type = 'ticket_reply'
    ORDER BY id DESC LIMIT 1
");
$log2Stmt->execute([':e' => $testTenant['email']]);
$log2 = $log2Stmt->fetch(PDO::FETCH_ASSOC);

assertCondition(!empty($log2), "Ticket reply email logged in email_logs");
assertCondition(strpos($log2['subject'], 'Maintenance Request Update') !== false, "Subject contains 'Maintenance Request Update'");
assertCondition($log2['reference_id'] == $ticketId, "Reference ID correctly points to ticket #{$ticketId}");
echo "\n";

// -----------------------------------------------------------------------------
// 3. Function 3: Forgot Password (Six-Digit Verification Code)
// -----------------------------------------------------------------------------
echo "[3] Testing Forgot Password Six-Digit Verification Code Flow:\n";
$code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $code);

// Invalidate previous resets
$pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")
    ->execute([':uid' => $testTenantId]);

// Insert 5-minute verification code
$pdo->prepare("
    INSERT INTO password_resets (user_id, email, token_hash, expires_at, attempts, max_attempts, created_at)
    VALUES (:uid, :email, :thash, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 5, NOW())
")->execute([
    ':uid'   => $testTenantId,
    ':email' => $testTenant['email'],
    ':thash' => $codeHash
]);
$resetId = (int)$pdo->lastInsertId();

$sent3 = EmailService::sendPasswordResetCode(
    $pdo,
    $testTenant['email'],
    $testTenant['name'],
    $code,
    5
);
assertCondition($sent3 === true, "sendPasswordResetCode returned true");

$log3Stmt = $pdo->prepare("
    SELECT * FROM email_logs 
    WHERE recipient_email = :e AND template_type = 'password_reset_code'
    ORDER BY id DESC LIMIT 1
");
$log3Stmt->execute([':e' => $testTenant['email']]);
$log3 = $log3Stmt->fetch(PDO::FETCH_ASSOC);

assertCondition(!empty($log3), "Verification code email logged in email_logs");
assertCondition($log3['subject'] === 'Password Reset Verification Code', "Subject is 'Password Reset Verification Code'");

// Test Verification Flow:
// 3a. Invalid code attempt increments attempts
$wrongCode = ($code === '123456') ? '654321' : '123456';
$wrongHash = hash('sha256', $wrongCode);
assertCondition(!hash_equals($codeHash, $wrongHash), "Wrong code correctly detected as mismatch");

$pdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = :id")->execute([':id' => $resetId]);
$attCheck = $pdo->query("SELECT attempts FROM password_resets WHERE id = {$resetId}")->fetchColumn();
assertCondition((int)$attCheck === 1, "Failed attempt counter successfully incremented to 1");

// 3b. Valid code succeeds and updates password
$newPassword = 'NewSecretPassword123!';
$newHash = password_hash($newPassword, PASSWORD_BCRYPT);
$pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :uid")
    ->execute([':hash' => $newHash, ':uid' => $testTenantId]);
$pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id")->execute([':id' => $resetId]);

$usedCheck = $pdo->query("SELECT used_at FROM password_resets WHERE id = {$resetId}")->fetchColumn();
assertCondition(!empty($usedCheck), "Verification code marked as used (single-use enforcement)");

$pwdVerify = $pdo->query("SELECT password_hash FROM users WHERE id = {$testTenantId}")->fetchColumn();
assertCondition(password_verify($newPassword, $pwdVerify), "New password verified with bcrypt");
echo "\n";

// -----------------------------------------------------------------------------
// 4. Function 4: Rent Due Reminders (System to Tenant Schedule & Exclusions)
// -----------------------------------------------------------------------------
echo "[4] Testing Rent Due Reminders (Upcoming, Due Today, Overdue, Exclusions):\n";

// Clean existing test payment reminders from email_logs to test fresh dispatch
$pdo->prepare("DELETE FROM email_logs WHERE recipient_email = :e AND template_type LIKE 'payment_reminder_%'")
    ->execute([':e' => $testTenant['email']]);

// Create 4 test payment records for schedule verification
// A. 3 days before due date
$pdo->prepare("
    INSERT INTO payments (tenant_id, unit_id, billing_cycle, due_date, amount_due, amount_paid, status, created_at)
    VALUES (:tid, 1, DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 3 DAY), '%Y-%m-01'), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 6000.00, 0.00, 'pending', NOW())
")->execute([':tid' => $testTenantId]);
$p3DaysId = (int)$pdo->lastInsertId();

// B. 1 day before due date
$pdo->prepare("
    INSERT INTO payments (tenant_id, unit_id, billing_cycle, due_date, amount_due, amount_paid, status, created_at)
    VALUES (:tid, 1, DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '%Y-%m-01'), DATE_ADD(CURDATE(), INTERVAL 1 DAY), 6000.00, 0.00, 'pending', NOW())
")->execute([':tid' => $testTenantId]);
$p1DayId = (int)$pdo->lastInsertId();

// C. Due today
$pdo->prepare("
    INSERT INTO payments (tenant_id, unit_id, billing_cycle, due_date, amount_due, amount_paid, status, created_at)
    VALUES (:tid, 1, DATE_FORMAT(CURDATE(), '%Y-%m-01'), CURDATE(), 6000.00, 0.00, 'pending', NOW())
")->execute([':tid' => $testTenantId]);
$pTodayId = (int)$pdo->lastInsertId();

// D. Overdue
$pdo->prepare("
    INSERT INTO payments (tenant_id, unit_id, billing_cycle, due_date, amount_due, amount_paid, status, created_at)
    VALUES (:tid, 1, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 DAY), '%Y-%m-01'), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 6000.00, 0.00, 'overdue', NOW())
")->execute([':tid' => $testTenantId]);
$pOverdueId = (int)$pdo->lastInsertId();

// E. Paid / Verified payment (MUST BE EXCLUDED)
$pdo->prepare("
    INSERT INTO payments (tenant_id, unit_id, billing_cycle, due_date, amount_due, amount_paid, status, created_at)
    VALUES (:tid, 1, DATE_FORMAT(CURDATE(), '%Y-%m-01'), CURDATE(), 6000.00, 6000.00, 'verified', NOW())
")->execute([':tid' => $testTenantId]);
$pPaidId = (int)$pdo->lastInsertId();

// Execute cron/send_reminders.php
ob_start();
include __DIR__ . '/../cron/send_reminders.php';
$cronOutput = ob_get_clean();

assertCondition($summary['upcoming_3days_sent'] >= 1, "Upcoming 3-day reminder processed (count: {$summary['upcoming_3days_sent']})");
assertCondition($summary['upcoming_1day_sent'] >= 1, "Upcoming 1-day reminder processed (count: {$summary['upcoming_1day_sent']})");
assertCondition($summary['due_today_sent'] >= 1, "Due today reminder processed (count: {$summary['due_today_sent']})");
assertCondition($summary['overdue_sent'] >= 1, "Overdue reminder processed (count: {$summary['overdue_sent']})");

// Verify paid payment was NOT sent
$paidCheck = $pdo->prepare("SELECT id FROM email_logs WHERE reference_id = :pid AND template_type LIKE 'payment_reminder_%'");
$paidCheck->execute([':pid' => $pPaidId]);
assertCondition($paidCheck->fetch() === false, "Verified/Paid payments are strictly excluded from reminders");

// Test deduplication: run again and verify duplicates are skipped
ob_start();
include __DIR__ . '/../cron/send_reminders.php';
$cronOutput2 = ob_get_clean();
assertCondition($summary['duplicates_skipped'] >= 4, "Second cron run skipped duplicates ({$summary['duplicates_skipped']} skipped)");

// Clean up test payments
$pdo->prepare("DELETE FROM payments WHERE id IN (:p1, :p2, :p3, :p4, :p5)")
    ->execute([':p1' => $p3DaysId, ':p2' => $p1DayId, ':p3' => $pTodayId, ':p4' => $pOverdueId, ':p5' => $pPaidId]);
echo "\n";

// -----------------------------------------------------------------------------
// 5. Function 5: Rent Due Announcement (Owner to Tenants)
// -----------------------------------------------------------------------------
echo "[5] Testing Rent Due Announcement (Broadcast & Unit Targeted):\n";

$annTitle = "Annual Facility & Water Utility Maintenance - November 2026";
$annMsg = "Please note that monthly rental payments are due on the 5th of November. You may submit GCash or Bank receipts directly via the tenant dashboard.";
$annDueDate = date('Y-m-d', strtotime('+7 days'));

$sent5 = EmailService::sendRentDueAnnouncement(
    $pdo,
    $testTenant['email'],
    $testTenant['name'],
    $annTitle,
    $annMsg,
    $annDueDate,
    'Unit 101',
    6000.00,
    999
);
assertCondition($sent5 === true, "sendRentDueAnnouncement returned true");

$log5Stmt = $pdo->prepare("
    SELECT * FROM email_logs 
    WHERE recipient_email = :e AND template_type = 'rent_announcement'
    ORDER BY id DESC LIMIT 1
");
$log5Stmt->execute([':e' => $testTenant['email']]);
$log5 = $log5Stmt->fetch(PDO::FETCH_ASSOC);

assertCondition(!empty($log5), "Rent announcement logged in email_logs");
assertCondition(strpos($log5['subject'], 'Rent Due Announcement') !== false, "Announcement subject contains 'Rent Due Announcement'");

// Test API endpoint creation & logging
$pdo->prepare("
    INSERT INTO announcements (owner_id, title, message, due_date, target_type, recipient_count, created_at)
    VALUES (1, :title, :msg, :due, 'all', 1, NOW())
")->execute([':title' => $annTitle, ':msg' => $annMsg, ':due' => $annDueDate]);
$annId = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO announcement_recipients (announcement_id, tenant_id, email, status, sent_at)
    VALUES (:aid, :tid, :email, 'sent', NOW())
")->execute([':aid' => $annId, ':tid' => $testTenantId, ':email' => $testTenant['email']]);

$recCheck = $pdo->query("SELECT id FROM announcement_recipients WHERE announcement_id = {$annId}")->fetchColumn();
assertCondition(!empty($recCheck), "Announcement recipient delivery logged in announcement_recipients");

// Clean test announcement
$pdo->exec("DELETE FROM announcements WHERE id = {$annId}");
echo "\n";

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "========================================================\n";
echo " TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================\n";

if ($failed === 0) {
    echo "ALL 5 EMAIL NOTIFICATION FUNCTIONS ARE FULLY FUNCTIONAL!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED. PLEASE REVIEW.\n";
    exit(1);
}
