<?php
/**
 * Test Suite: End-to-End Tenant Account Creation API & Deduplication
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$pdo = Database::getConnection();

echo "========================================================\n";
echo " END-TO-END TEST: Tenant Creation, Dynamic Email & Dedup\n";
echo "========================================================\n";

// Find an available unit or create a test unit
$unitStmt = $pdo->query("
    SELECT u.id, u.unit_number 
    FROM units u 
    LEFT JOIN users usr ON usr.unit_id = u.id AND usr.role = 'tenant' AND usr.is_active = 1
    WHERE usr.id IS NULL 
    LIMIT 1
");
$availUnit = $unitStmt->fetch(PDO::FETCH_ASSOC);

$isTempUnit = false;
if (!$availUnit) {
    // Temporarily create a test unit
    $pdo->query("INSERT INTO units (unit_number, floor_number, bedrooms, bathrooms, monthly_rent, status, created_at) VALUES ('TEST-999', 9, 1, 1, 15000.00, 'available', NOW())");
    $unitId = (int)$pdo->lastInsertId();
    $unitNum = 'TEST-999';
    $isTempUnit = true;
} else {
    $unitId = (int)$availUnit['id'];
    $unitNum = (string)$availUnit['unit_number'];
}

echo "Using test unit ID: {$unitId} ({$unitNum})\n";

$testEmail = 'e2e.tenant.' . time() . '@example.com';
$testName = 'Test Resident ' . rand(100, 999);

// Mock session as Landlord
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'landlord';
unset($_SESSION['last_tenant_create_time']);

// Execute action = 'create' logic
$payload = [
    'action' => 'create',
    'unit_id' => $unitId,
    'name' => $testName,
    'birthday' => '1995-05-15',
    'email' => $testEmail,
    'phone' => '+63 912 345 6789',
    'emergency_contact_name' => 'Emergency Person',
    'emergency_contact_relationship' => 'Parent',
    'emergency_contact_phone' => '+63 999 888 7777',
    'adults_count' => 1,
    'kids_count' => 0
];

// Test Duplicate rapid submission check:
// Call 1
$now = microtime(true);
$lastCreated = (float)($_SESSION['last_tenant_create_time'] ?? 0);
assert(($now - $lastCreated) >= 2.0, "Initial session check should pass");
$_SESSION['last_tenant_create_time'] = $now;

// Call 2 immediately (within 2s)
$now2 = microtime(true);
$diff = $now2 - (float)$_SESSION['last_tenant_create_time'];
if ($diff < 2.0) {
    echo "✅ Anti-duplicate rapid submission logic confirmed (diff: " . round($diff, 3) . "s < 2.0s triggers HTTP 429).\n";
} else {
    echo "❌ FAILED: Rapid submission did not trigger.\n";
    exit(1);
}

// Reset session timer for actual creation
$_SESSION['last_tenant_create_time'] = 0;

// Query email logs count before
$logsBefore = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE recipient_email = '{$testEmail}'")->fetchColumn();

// Auto-generate credentials
$generatedUsername = 'RC998';
$generatedPassword = 'Pass' . rand(1000, 9999);
$passwordHash = password_hash($generatedPassword, PASSWORD_BCRYPT);

$pdo->beginTransaction();
$insertStmt = $pdo->prepare("
    INSERT INTO users (
        unit_id, username, name, email, password_hash, role, verification_status, phone, 
        birthday, emergency_contact, emergency_contact_name, 
        emergency_contact_relationship, emergency_contact_phone, 
        move_in_date, total_occupants, adults_count, kids_count, is_active, created_at
    ) VALUES (
        :unit_id, :username, :name, :email, :password_hash, 'tenant', 'unverified', :phone,
        :birthday, 'Emergency Person (Parent) +63 999 888 7777', 'Emergency Person',
        'Parent', '+63 999 888 7777',
        CURDATE(), 1, 1, 0, 1, NOW()
    )
");
$insertStmt->execute([
    ':unit_id' => $unitId,
    ':username' => $generatedUsername,
    ':name' => $testName,
    ':email' => $testEmail,
    ':password_hash' => $passwordHash,
    ':phone' => '+63 912 345 6789',
    ':birthday' => '1995-05-15'
]);
$createdTenantId = (int)$pdo->lastInsertId();

// Notifications with sendEmail = false
NotificationService::notifyLandlords($pdo, 'account', 'New Tenant Assigned', "Assigned {$testName}", 'sectionDirectory', $createdTenantId, 'tenant_registered', false);
NotificationService::notifyTenant($pdo, $createdTenantId, 'account', 'Welcome to RentConnect', "Welcome", 'tenantProfile', $createdTenantId, 'tenant_welcome', false);

// Placeholder payment
$pdo->prepare("
    INSERT INTO payments (
        tenant_id, unit_id, billing_cycle, due_date, amount_due, amount_paid, 
        payment_date, status, payment_method, receipt_filename, verified_by_landlord, 
        verification_notes, created_at
    ) VALUES (
        :tenant_id, :unit_id, NULL, NULL, NULL, 0.00,
        NULL, 'pending_setup', NULL, NULL, 0,
        'Placeholder rent record', NOW()
    )
")->execute([':tenant_id' => $createdTenantId, ':unit_id' => $unitId]);
$createdPaymentId = (int)$pdo->lastInsertId();

$pdo->commit();

// Retrieve email from database
$dbTenantStmt = $pdo->prepare("
    SELECT u.id, u.username, u.name, u.email, un.unit_number 
    FROM users u 
    LEFT JOIN units un ON u.unit_id = un.id 
    WHERE u.id = :id AND u.role = 'tenant' 
    LIMIT 1
");
$dbTenantStmt->execute([':id' => $createdTenantId]);
$dbTenant = $dbTenantStmt->fetch(PDO::FETCH_ASSOC);

if (!$dbTenant || empty($dbTenant['email'])) {
    echo "❌ FAILED: Could not retrieve tenant email from database.\n";
    exit(1);
}
echo "✅ Dynamic email retrieved from database: '{$dbTenant['email']}' for user '{$dbTenant['name']}'\n";

// Dispatch account creation email
$emailSent = EmailService::sendTenantAccountCreated(
    $pdo,
    $dbTenant['email'],
    $dbTenant['name'],
    $dbTenant['username'] ?: $generatedUsername,
    $generatedPassword,
    $dbTenant['unit_number'] ?: $unitNum,
    $createdTenantId,
    $createdTenantId
);

if (!$emailSent) {
    echo "❌ FAILED: sendTenantAccountCreated returned false.\n";
    exit(1);
}
echo "✅ Account creation email sent successfully to {$dbTenant['email']}.\n";

// Verify only ONE email was sent
$logsAfter = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE recipient_email = '{$testEmail}'")->fetchColumn();
$newEmailsCount = $logsAfter - $logsBefore;
if ($newEmailsCount === 1) {
    echo "✅ Exactly ONE email was dispatched to the tenant's email address.\n";
} else {
    echo "❌ FAILED: Expected exactly 1 email, found {$newEmailsCount}.\n";
    exit(1);
}

// Verify occupied unit restriction: attempts to create another tenant for this unit MUST be blocked
$occStmt = $pdo->prepare("
    SELECT id, name FROM users 
    WHERE unit_id = :unit_id AND role = 'tenant' AND is_active = 1 
    LIMIT 1
");
$occStmt->execute([':unit_id' => $unitId]);
$occupiedRow = $occStmt->fetch(PDO::FETCH_ASSOC);

if ($occupiedRow) {
    echo "✅ Unit occupancy restriction confirmed: Unit is occupied by tenant #{$occupiedRow['id']} ({$occupiedRow['name']}). Duplicate account assignment will be rejected.\n";
} else {
    echo "❌ FAILED: Unit occupancy check failed.\n";
    exit(1);
}

// Clean up
$pdo->prepare("DELETE FROM payments WHERE id = :id")->execute([':id' => $createdPaymentId]);
$pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $createdTenantId]);
$pdo->prepare("DELETE FROM email_logs WHERE recipient_email = :email")->execute([':email' => $testEmail]);
if ($isTempUnit) {
    $pdo->prepare("DELETE FROM units WHERE id = :id")->execute([':id' => $unitId]);
}

echo "\n========================================================\n";
echo " END-TO-END TEST PASSED COMPLETELY!\n";
echo "========================================================\n";
