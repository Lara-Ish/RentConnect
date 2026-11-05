<?php
/**
 * Test Suite: Dynamic Database Email Retrieval & PHPMailer Integration
 * Validates that all recipient emails are queried dynamically from MySQL database
 * and logged with recipient_user_id, recipient_email, template_type, status, and error_message.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$pdo = Database::getConnection();

echo "=======================================================================\n";
echo " VERIFYING DYNAMIC DATABASE EMAIL RETRIEVAL & PHPMAILER DISPATCH\n";
echo "=======================================================================\n\n";

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failed++;
    }
}

// -----------------------------------------------------------------------------
// STEP 1: Verify Database Schema
// -----------------------------------------------------------------------------
echo "[STEP 1] Verifying Database Schema for Email Retrieval & Audit Logging:\n";

// Check users table columns
$userCols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
assertCheck(in_array('email', $userCols, true), "Column 'email' exists in 'users' table.");
assertCheck(in_array('role', $userCols, true), "Column 'role' exists in 'users' table.");
assertCheck(in_array('unit_id', $userCols, true), "Column 'unit_id' exists in 'users' table.");

// Check email_logs table columns
$logCols = $pdo->query("DESCRIBE email_logs")->fetchAll(PDO::FETCH_COLUMN);
assertCheck(in_array('recipient_user_id', $logCols, true), "Column 'recipient_user_id' exists in 'email_logs' table.");
assertCheck(in_array('recipient_email', $logCols, true), "Column 'recipient_email' exists in 'email_logs' table.");
assertCheck(in_array('template_type', $logCols, true), "Column 'template_type' exists in 'email_logs' table.");
assertCheck(in_array('status', $logCols, true), "Column 'status' exists in 'email_logs' table.");
assertCheck(in_array('error_message', $logCols, true), "Column 'error_message' exists in 'email_logs' table.");
echo "\n";

// -----------------------------------------------------------------------------
// STEP 2: Reusable sendEmail() Function Test
// -----------------------------------------------------------------------------
echo "[STEP 2] Testing Reusable sendEmail(\$recipientEmail, \$subject, \$message, \$recipientUserId, \$pdo):\n";
$owner = EmailService::getOwnerFromDatabase($pdo);
assertCheck(!empty($owner), "Queried active Owner account from database (ID: {$owner['id']}, Name: {$owner['name']}, Email: {$owner['email']}).");

$subjectTest = "Test Reusable sendEmail Function " . time();
$msgTest = "<p>This is a test of the reusable sendEmail function with database-retrieved email.</p>";
$sentResult = sendEmail($owner['email'], $subjectTest, $msgTest, $owner['id'], $pdo);
assertCheck($sentResult === true, "sendEmail() returned true for database-retrieved email.");

$logStmt = $pdo->prepare("
    SELECT * FROM email_logs 
    WHERE recipient_email = :e AND subject = :s 
    ORDER BY id DESC LIMIT 1
");
$logStmt->execute([':e' => $owner['email'], ':s' => $subjectTest]);
$ownerLog = $logStmt->fetch(PDO::FETCH_ASSOC);

assertCheck(!empty($ownerLog), "Email dispatch logged in email_logs table.");
assertCheck((int)$ownerLog['recipient_user_id'] === (int)$owner['id'], "email_logs.recipient_user_id correctly matches DB user ID ({$owner['id']}).");
assertCheck($ownerLog['recipient_email'] === $owner['email'], "email_logs.recipient_email correctly matches DB email ({$owner['email']}).");
assertCheck(in_array($ownerLog['status'], ['sent', 'mock_sent'], true), "email_logs.status is '{$ownerLog['status']}'.");
echo "\n";

// -----------------------------------------------------------------------------
// STEP 3: Tenant Account Creation (Owner to Tenant)
// -----------------------------------------------------------------------------
echo "[STEP 3] Testing Tenant Account Creation with Database Email Retrieval:\n";
// Find an available unoccupied unit or pick Unit 2
$unoccupiedUnitStmt = $pdo->query("
    SELECT id, unit_number FROM units 
    WHERE id NOT IN (SELECT unit_id FROM users WHERE role = 'tenant' AND is_active = 1) 
    ORDER BY id ASC LIMIT 1
");
$freeUnit = $unoccupiedUnitStmt->fetch(PDO::FETCH_ASSOC);

if (!$freeUnit) {
    // Temporarily create a unit for testing
    $pdo->prepare("INSERT INTO units (unit_number, bedrooms, monthly_rent, created_at) VALUES ('Unit-DB-Test', 1, 8500.00, NOW())")->execute();
    $testUnitId = (int)$pdo->lastInsertId();
    $testUnitNumber = 'Unit-DB-Test';
} else {
    $testUnitId = (int)$freeUnit['id'];
    $testUnitNumber = (string)$freeUnit['unit_number'];
}

$testEmail = 'tenant_db_test_' . time() . '@example.com';
$testName = 'Test Tenant DB Query';
$testPass = password_hash('TestPass123!', PASSWORD_BCRYPT);

// Simulate Owner creating new Tenant record in MySQL database
$pdo->prepare("
    INSERT INTO users (unit_id, username, name, email, password_hash, role, verification_status, is_active, created_at)
    VALUES (:uid, :uname, :name, :email, :pwd, 'tenant', 'unverified', 1, NOW())
")->execute([
    ':uid'   => $testUnitId,
    ':uname' => 'RC_TEST_' . time(),
    ':name'  => $testName,
    ':email' => $testEmail,
    ':pwd'   => $testPass
]);
$newTenantId = (int)$pdo->lastInsertId();
assertCheck($newTenantId > 0, "Saved new Tenant record in MySQL database (ID: {$newTenantId}, Email: {$testEmail}).");

// CRITICAL STEP: Retrieve email address directly from MySQL database record by tenant ID
$retrievedTenant = EmailService::getTenantFromDatabase($pdo, $newTenantId);
assertCheck(!empty($retrievedTenant), "Dynamically retrieved saved tenant record from database by ID ({$newTenantId}).");
assertCheck($retrievedTenant['email'] === $testEmail, "Retrieved database email matches exactly: {$retrievedTenant['email']}");

// Generate secure setup link
$setupToken = bin2hex(random_bytes(32));
$setupTokenHash = hash('sha256', $setupToken);
$pdo->prepare("
    INSERT INTO password_resets (user_id, email, token_hash, expires_at, created_at)
    VALUES (:uid, :email, :thash, DATE_ADD(NOW(), INTERVAL 48 HOUR), NOW())
")->execute([
    ':uid'   => $newTenantId,
    ':email' => $retrievedTenant['email'],
    ':thash' => $setupTokenHash
]);

$config = MailConfig::get();
$setupUrl = $config['app_url'] . '/reset_password.php?token=' . $setupToken;

// Send account creation email to the database-retrieved email
$creationSent = EmailService::sendTenantAccountCreated(
    $pdo,
    $retrievedTenant['email'],
    $retrievedTenant['name'],
    $testUnitNumber,
    $setupUrl,
    $newTenantId,
    $newTenantId
);
assertCheck($creationSent === true, "sendTenantAccountCreated() dispatched to database-retrieved email.");

// Verify email_logs
$creationLogStmt = $pdo->prepare("
    SELECT * FROM email_logs 
    WHERE recipient_email = :e AND template_type = 'tenant_account_created' 
    ORDER BY id DESC LIMIT 1
");
$creationLogStmt->execute([':e' => $retrievedTenant['email']]);
$creationLog = $creationLogStmt->fetch(PDO::FETCH_ASSOC);

assertCheck(!empty($creationLog), "Tenant creation email recorded in email_logs.");
assertCheck((int)$creationLog['recipient_user_id'] === $newTenantId, "email_logs.recipient_user_id ({$creationLog['recipient_user_id']}) matches new tenant ID ({$newTenantId}).");
assertCheck($creationLog['recipient_email'] === $testEmail, "email_logs.recipient_email ({$creationLog['recipient_email']}) matches database email.");
assertCheck(in_array($creationLog['status'], ['sent', 'mock_sent'], true), "email_logs.status is '{$creationLog['status']}'.");

// Clean up temporary tenant and password reset record
$pdo->prepare("DELETE FROM password_resets WHERE user_id = :uid")->execute([':uid' => $newTenantId]);
$pdo->prepare("DELETE FROM users WHERE id = :uid")->execute([':uid' => $newTenantId]);
if ($testUnitNumber === 'Unit-DB-Test') {
    $pdo->prepare("DELETE FROM units WHERE id = :uid")->execute([':uid' => $testUnitId]);
}
echo "\n";

// -----------------------------------------------------------------------------
// STEP 4: Maintenance Ticket Replies (Owner to Tenant)
// -----------------------------------------------------------------------------
echo "[STEP 4] Testing Maintenance Ticket Reply with Database Email Retrieval:\n";
// Retrieve an active tenant from database
$activeTenantStmt = $pdo->query("SELECT id, name, email, unit_id FROM users WHERE role = 'tenant' AND is_active = 1 LIMIT 1");
$activeTenant = $activeTenantStmt->fetch(PDO::FETCH_ASSOC);

if (!$activeTenant) {
    echo "  [SKIP] No active tenant found to create ticket for.\n";
} else {
    $activeTenantId = (int)$activeTenant['id'];
    $activeUnitId = (int)$activeTenant['unit_id'];

    // Insert a test ticket for this tenant
    $pdo->prepare("
        INSERT INTO maintenance_tickets (tenant_id, unit_id, title, description, category, priority, status, created_at)
        VALUES (:tid, :uid, 'Leaking Kitchen Pipe', 'Sink pipe is dripping constantly.', 'Plumbing', 'high', 'open', NOW())
    ")->execute([':tid' => $activeTenantId, ':uid' => $activeUnitId]);
    $ticketId = (int)$pdo->lastInsertId();

    // Query ticket with JOIN on users to retrieve tenant email dynamically
    $queryTicketStmt = $pdo->prepare("
        SELECT mt.id, mt.title, mt.tenant_id, mt.status, u.name AS tenant_name, u.email AS tenant_email, un.unit_number
        FROM maintenance_tickets mt
        JOIN users u ON mt.tenant_id = u.id
        LEFT JOIN units un ON mt.unit_id = un.id
        WHERE mt.id = :tid
        LIMIT 1
    ");
    $queryTicketStmt->execute([':tid' => $ticketId]);
    $dbTicket = $queryTicketStmt->fetch(PDO::FETCH_ASSOC);

    assertCheck(!empty($dbTicket), "Ticket joined with users table to retrieve tenant.");
    assertCheck($dbTicket['tenant_email'] === $activeTenant['email'], "Tenant email retrieved via DB relationship: {$dbTicket['tenant_email']}");
    assertCheck((int)$dbTicket['tenant_id'] === $activeTenantId, "Tenant ID retrieved via DB relationship: {$dbTicket['tenant_id']}");

    // Send ticket reply email using ONLY the database-retrieved email
    $ownerReply = "We have dispatched a plumber. They will arrive tomorrow at 10 AM.";
    $replySent = EmailService::notifyTenantTicketReply(
        $pdo,
        (string)$dbTicket['tenant_email'],
        (string)$dbTicket['tenant_name'],
        (string)$dbTicket['title'],
        $ownerReply,
        'in_progress',
        $ticketId,
        (int)$dbTicket['tenant_id']
    );
    assertCheck($replySent === true, "notifyTenantTicketReply() dispatched to database-retrieved email.");

    $ticketLogStmt = $pdo->prepare("
        SELECT * FROM email_logs 
        WHERE recipient_email = :e AND template_type = 'ticket_reply' AND reference_id = :ref
        ORDER BY id DESC LIMIT 1
    ");
    $ticketLogStmt->execute([':e' => $dbTicket['tenant_email'], ':ref' => $ticketId]);
    $ticketLog = $ticketLogStmt->fetch(PDO::FETCH_ASSOC);

    assertCheck(!empty($ticketLog), "Ticket reply email recorded in email_logs.");
    assertCheck((int)$ticketLog['recipient_user_id'] === $activeTenantId, "email_logs.recipient_user_id ({$ticketLog['recipient_user_id']}) matches DB tenant ID ({$activeTenantId}).");
    assertCheck($ticketLog['recipient_email'] === $activeTenant['email'], "email_logs.recipient_email matches tenant DB email.");

    // Clean up test ticket
    $pdo->prepare("DELETE FROM maintenance_tickets WHERE id = :id")->execute([':id' => $ticketId]);
}
echo "\n";

// -----------------------------------------------------------------------------
// STEP 5: Forgot Password (6-Digit Code)
// -----------------------------------------------------------------------------
echo "[STEP 5] Testing Forgot Password with Database Email Retrieval:\n";
// Retrieve existing active user from users table
$existingUserStmt = $pdo->query("SELECT id, name, email FROM users WHERE is_active = 1 LIMIT 1");
$existingUser = $existingUserStmt->fetch(PDO::FETCH_ASSOC);

$userSearchStmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = :e AND is_active = 1 LIMIT 1");
$userSearchStmt->execute([':e' => $existingUser['email']]);
$retrievedUser = $userSearchStmt->fetch(PDO::FETCH_ASSOC);

assertCheck(!empty($retrievedUser), "User account found in database: {$retrievedUser['name']} ({$retrievedUser['email']}).");

$code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
$pwdResetSent = EmailService::sendPasswordResetCode(
    $pdo,
    (string)$retrievedUser['email'],
    (string)$retrievedUser['name'],
    $code,
    5,
    (int)$retrievedUser['id']
);
assertCheck($pwdResetSent === true, "sendPasswordResetCode() dispatched to database-retrieved email.");

$resetLogStmt = $pdo->prepare("
    SELECT * FROM email_logs 
    WHERE recipient_email = :e AND template_type = 'password_reset_code'
    ORDER BY id DESC LIMIT 1
");
$resetLogStmt->execute([':e' => $retrievedUser['email']]);
$resetLog = $resetLogStmt->fetch(PDO::FETCH_ASSOC);

assertCheck(!empty($resetLog), "Password reset code recorded in email_logs.");
assertCheck((int)$resetLog['recipient_user_id'] === (int)$retrievedUser['id'], "email_logs.recipient_user_id ({$resetLog['recipient_user_id']}) matches DB user ID ({$retrievedUser['id']}).");
assertCheck($resetLog['recipient_email'] === $retrievedUser['email'], "email_logs.recipient_email matches database email.");
// Security check: ensure 6-digit code is NOT written into email_logs
assertCheck(strpos((string)$resetLog['error_message'], $code) === false, "Verification code is NOT logged in email_logs (Security requirement verified).");
echo "\n";

// -----------------------------------------------------------------------------
// STEP 6: Rent Due Reminders (Payment to Tenant)
// -----------------------------------------------------------------------------
echo "[STEP 6] Testing Rent Due Reminders with Database Email Retrieval:\n";
// Query unpaid payment record joined with users table
$unpaidStmt = $pdo->query("
    SELECT p.id as payment_id, p.tenant_id, p.amount_due, p.due_date, 
           u.name as tenant_name, u.email as tenant_email, un.unit_number
    FROM payments p
    JOIN users u ON p.tenant_id = u.id
    LEFT JOIN units un ON p.unit_id = un.id
    WHERE p.status NOT IN ('paid', 'verified', 'pending_setup')
      AND p.amount_paid = 0
      AND p.due_date IS NOT NULL
      AND u.is_active = 1
    LIMIT 1
");
$unpaidPayment = $unpaidStmt->fetch(PDO::FETCH_ASSOC);

if (!$unpaidPayment && $activeTenant) {
    // Create a temporary unpaid payment record for testing
    $nextDue = date('Y-m-d', strtotime('+3 days'));
    $pdo->prepare("
        INSERT INTO payments (tenant_id, unit_id, billing_cycle, due_date, amount_due, amount_paid, status, created_at)
        VALUES (:tid, :uid, :bcycle, :due, 8500.00, 0.00, 'pending', NOW())
    ")->execute([
        ':tid'    => $activeTenant['id'],
        ':uid'    => $activeTenant['unit_id'] ?: 1,
        ':bcycle' => date('Y-m-01'),
        ':due'    => $nextDue
    ]);
    $tempPaymentId = (int)$pdo->lastInsertId();

    $unpaidPayment = [
        'payment_id'   => $tempPaymentId,
        'tenant_id'    => (int)$activeTenant['id'],
        'amount_due'   => 8500.00,
        'due_date'     => $nextDue,
        'tenant_name'  => $activeTenant['name'],
        'tenant_email' => $activeTenant['email'],
        'unit_number'  => 'Unit 1'
    ];
}

assertCheck(!empty($unpaidPayment), "Unpaid payment record joined with tenant user in DB.");
assertCheck(filter_var($unpaidPayment['tenant_email'], FILTER_VALIDATE_EMAIL) !== false, "Tenant email retrieved dynamically via payments -> users foreign key: {$unpaidPayment['tenant_email']}");

$reminderSent = EmailService::notifyTenantPaymentReminder(
    $pdo,
    (string)$unpaidPayment['tenant_email'],
    (string)$unpaidPayment['tenant_name'],
    (string)$unpaidPayment['unit_number'],
    (float)$unpaidPayment['amount_due'],
    (string)$unpaidPayment['due_date'],
    'upcoming_3days',
    (int)$unpaidPayment['payment_id'],
    (int)$unpaidPayment['tenant_id']
);
assertCheck($reminderSent === true, "notifyTenantPaymentReminder() dispatched to database-retrieved email.");

$reminderLogStmt = $pdo->prepare("
    SELECT * FROM email_logs 
    WHERE recipient_email = :e AND template_type = 'payment_reminder_upcoming_3days' AND reference_id = :pid
    ORDER BY id DESC LIMIT 1
");
$reminderLogStmt->execute([':e' => $unpaidPayment['tenant_email'], ':pid' => $unpaidPayment['payment_id']]);
$reminderLog = $reminderLogStmt->fetch(PDO::FETCH_ASSOC);

assertCheck(!empty($reminderLog), "Payment reminder recorded in email_logs.");
assertCheck((int)$reminderLog['recipient_user_id'] === (int)$unpaidPayment['tenant_id'], "email_logs.recipient_user_id ({$reminderLog['recipient_user_id']}) matches DB tenant ID ({$unpaidPayment['tenant_id']}).");
assertCheck($reminderLog['recipient_email'] === $unpaidPayment['tenant_email'], "email_logs.recipient_email matches DB email.");

// Clean up temp payment if created
if (isset($tempPaymentId)) {
    $pdo->prepare("DELETE FROM payments WHERE id = :id")->execute([':id' => $tempPaymentId]);
}
echo "\n";

// -----------------------------------------------------------------------------
// STEP 7: Rent Due Announcements (Owner to Tenants)
// -----------------------------------------------------------------------------
echo "[STEP 7] Testing Rent Due Announcements with Database Email Retrieval:\n";
// Query all active tenants dynamically from DB
$allTenantsStmt = $pdo->query("
    SELECT u.id as tenant_id, u.name as tenant_name, u.email as tenant_email, 
           un.id as unit_id, un.unit_number, un.monthly_rent
    FROM users u
    JOIN units un ON u.unit_id = un.id
    WHERE u.role = 'tenant' AND u.is_active = 1
    ORDER BY un.unit_number ASC
");
$tenantsList = $allTenantsStmt->fetchAll(PDO::FETCH_ASSOC);
assertCheck(!empty($tenantsList), "Retrieved " . count($tenantsList) . " active tenant(s) dynamically from database.");

$annTitle = "Monthly Rental Settlement Notice " . date('F Y');
$annMsg = "Please be reminded that rent for " . date('F Y') . " is due on the 5th. Kindly submit receipts via the portal.";

// Insert announcement record
$pdo->prepare("
    INSERT INTO announcements (owner_id, title, message, due_date, target_type, recipient_count, created_at)
    VALUES (:oid, :title, :msg, :due, 'all', :rcount, NOW())
")->execute([
    ':oid'    => $owner['id'],
    ':title'  => $annTitle,
    ':msg'    => $annMsg,
    ':due'    => date('Y-m-05'),
    ':rcount' => count($tenantsList)
]);
$announcementId = (int)$pdo->lastInsertId();

$annSuccessCount = 0;
foreach ($tenantsList as $t) {
    $tid = (int)$t['tenant_id'];
    $tEmail = (string)$t['tenant_email'];
    $tName = (string)$t['tenant_name'];
    $uNum = (string)$t['unit_number'];
    $amt = (float)$t['monthly_rent'];

    $annSent = EmailService::sendRentDueAnnouncement(
        $pdo,
        $tEmail,
        $tName,
        $annTitle,
        $annMsg,
        date('Y-m-05'),
        $uNum,
        $amt,
        $announcementId,
        $tid
    );
    if ($annSent) {
        $annSuccessCount++;
    }
}
assertCheck($annSuccessCount === count($tenantsList), "All announcements dispatched successfully to database-retrieved emails ({$annSuccessCount}/" . count($tenantsList) . ").");

// Verify recipient logs
$annLogStmt = $pdo->prepare("
    SELECT * FROM email_logs 
    WHERE template_type = 'rent_announcement' AND reference_id = :aid
    ORDER BY id DESC LIMIT 1
");
$annLogStmt->execute([':aid' => $announcementId]);
$annLog = $annLogStmt->fetch(PDO::FETCH_ASSOC);

assertCheck(!empty($annLog), "Announcement email recorded in email_logs.");
assertCheck(!empty($annLog['recipient_user_id']), "email_logs.recipient_user_id is populated ({$annLog['recipient_user_id']}).");
assertCheck(filter_var($annLog['recipient_email'], FILTER_VALIDATE_EMAIL) !== false, "email_logs.recipient_email is valid database email ({$annLog['recipient_email']}).");

// Clean up test announcement
$pdo->prepare("DELETE FROM announcements WHERE id = :id")->execute([':id' => $announcementId]);
echo "\n";

// -----------------------------------------------------------------------------
// STEP 8: Error Handling & Security Validation
// -----------------------------------------------------------------------------
echo "[STEP 8] Testing Error Handling (Missing/Invalid Email & No Fallback):\n";
try {
    $invalidSent = sendEmail('not-an-email', 'Test Invalid Email', 'Body', null, $pdo);
    assertCheck(false, "sendEmail should throw Exception on invalid email.");
} catch (Exception $ex) {
    assertCheck(true, "sendEmail() threw Exception on invalid recipient: " . $ex->getMessage());
}

$invalidLogStmt = $pdo->query("
    SELECT * FROM email_logs 
    WHERE recipient_email = 'not-an-email' AND status = 'failed'
    ORDER BY id DESC LIMIT 1
");
$invalidLog = $invalidLogStmt->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($invalidLog), "Failed delivery attempt recorded in email_logs with status='failed'.");
assertCheck(!empty($invalidLog['error_message']), "Error message recorded in email_logs: {$invalidLog['error_message']}");
echo "\n";

// -----------------------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------------------
echo "=======================================================================\n";
echo " TEST SUMMARY: {$passed} PASSED, {$failed} FAILED\n";
echo "=======================================================================\n";

if ($failed === 0) {
    echo "SUCCESS: ALL TESTS PASSED! All recipient email addresses come dynamically from MySQL database.\n";
    exit(0);
} else {
    echo "FAILURE: {$failed} test(s) failed.\n";
    exit(1);
}
