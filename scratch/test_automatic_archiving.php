<?php
/**
 * Test Suite: TaraRent - Payment Verification and Automatic Archiving of Moved-Out Tenants
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/tenant_service.php';

$pdo = Database::getConnection();

function assertTest(string $desc, bool $condition, string $extra = ''): void {
    if ($condition) {
        echo "  [PASS] $desc\n";
    } else {
        echo " ![FAIL] $desc" . ($extra ? " ($extra)" : "") . "\n";
        exit(1);
    }
}

echo "======================================================================\n";
echo "TaraRent - Automatic Archiving & Payment Verification Integration Tests\n";
echo "======================================================================\n\n";

// Ensure mock session is landlord for API tests
$_SESSION['user'] = [
    'id' => 1,
    'username' => 'admin',
    'name' => 'Lead Dimaculangan',
    'email' => 'rentconnect2026@gmail.com',
    'role' => 'landlord'
];

// Clean up any old test tenants
$pdo->exec("DELETE FROM payments WHERE tenant_id IN (SELECT id FROM users WHERE username IN ('test_auto_arc1', 'test_auto_arc2', 'test_auto_arc3'))");
$pdo->exec("DELETE FROM users WHERE username IN ('test_auto_arc1', 'test_auto_arc2', 'test_auto_arc3')");

// -------------------------------------------------------------------------
// TEST 1: Tenant with Paid Bills who has NOT Moved Out (rental_status = 'active')
// Expected: MUST NOT be archived!
// -------------------------------------------------------------------------
echo "[1] Testing Tenant with Paid Bills who has NOT Moved Out...\n";

$pdo->prepare("
    INSERT INTO users (username, name, email, password_hash, role, rental_status, move_in_date, unit_id, is_active, is_archived)
    VALUES ('test_auto_arc1', 'Test Active Tenant', 'test_arc1@example.com', 'hash123', 'tenant', 'active', '2026-01-01', 2, 1, 0)
")->execute();
$tenant1Id = (int)$pdo->lastInsertId();

// Create 1 approved payment with balance = 0
$pdo->prepare("
    INSERT INTO payments (tenant_id, unit_id, payment_type, billing_cycle, due_date, amount_due, amount_paid, payment_date, payment_method, status, verified_by_landlord)
    VALUES (:tid, 2, 'Rent', '2026-10-01', '2026-10-05', 3000.00, 3000.00, '2026-10-04', 'GCash', 'approved', 1)
")->execute([':tid' => $tenant1Id]);

$settlement1 = TenantService::checkTenantSettlement($pdo, $tenant1Id);
assertTest("checkTenantSettlement confirms bills are settled", $settlement1['is_settled'] === true);

// Run syncMovedOutSettlements
$syncedCount = TenantService::syncMovedOutSettlements($pdo);
assertTest("syncMovedOutSettlements did NOT archive active resident", $syncedCount === 0);

$checkT1 = $pdo->query("SELECT is_active, is_archived, rental_status FROM users WHERE id = $tenant1Id")->fetch(PDO::FETCH_ASSOC);
assertTest("Tenant 1 remains is_active = 1", (int)$checkT1['is_active'] === 1);
assertTest("Tenant 1 remains is_archived = 0", (int)$checkT1['is_archived'] === 0);


// -------------------------------------------------------------------------
// TEST 2: Tenant Marked Moved Out, but Has UNPAID Bills
// Expected: Mark as moved_out, but DO NOT archive! Must stay active.
// -------------------------------------------------------------------------
echo "\n[2] Testing Tenant Marked Moved Out with UNPAID Bills...\n";

$pdo->prepare("
    INSERT INTO users (username, name, email, password_hash, role, rental_status, move_in_date, unit_id, is_active, is_archived)
    VALUES ('test_auto_arc2', 'Test Unpaid Moveout', 'test_arc2@example.com', 'hash123', 'tenant', 'active', '2026-02-01', 3, 1, 0)
")->execute();
$tenant2Id = (int)$pdo->lastInsertId();

// Add unpaid bill
$pdo->prepare("
    INSERT INTO payments (tenant_id, unit_id, payment_type, billing_cycle, due_date, amount_due, amount_paid, status)
    VALUES (:tid, 3, 'Rent', '2026-10-01', '2026-10-05', 3000.00, 0.00, 'unpaid')
")->execute([':tid' => $tenant2Id]);

$markRes2 = TenantService::markMovedOut($pdo, $tenant2Id);
assertTest("markMovedOut returned status = 'pending_settlement'", $markRes2['status'] === 'pending_settlement');
assertTest("Tenant 2 was NOT archived", $markRes2['archived'] === false);
assertTest("Settlement indicates unpaid bill", $markRes2['settlement']['unpaid_count'] === 1);
assertTest("Settlement reports ₱3,000.00 balance", $markRes2['settlement']['unpaid_balance'] == 3000.00);

$checkT2 = $pdo->query("SELECT is_active, is_archived, rental_status FROM users WHERE id = $tenant2Id")->fetch(PDO::FETCH_ASSOC);
assertTest("Tenant 2 rental_status updated to moved_out", $checkT2['rental_status'] === 'moved_out');
assertTest("Tenant 2 remains is_active = 1", (int)$checkT2['is_active'] === 1);
assertTest("Tenant 2 remains is_archived = 0", (int)$checkT2['is_archived'] === 0);

// Verify Tenant 2 payment is still present in Active Payment Verification query
$activePaymentsT2 = $pdo->query("
    SELECT p.id FROM payments p
    JOIN users u ON p.tenant_id = u.id
    WHERE (u.is_archived = 0 OR u.is_archived IS NULL)
      AND u.is_active = 1
      AND u.deleted_at IS NULL
      AND p.tenant_id = $tenant2Id
")->fetchAll();
assertTest("Tenant 2 unpaid payment remains visible in Active Payment Verification", count($activePaymentsT2) === 1);


// -------------------------------------------------------------------------
// TEST 3: Tenant Marked Moved Out with PENDING Payment Verification
// Expected: Keep active until approved! When approved -> Auto-Archive!
// -------------------------------------------------------------------------
echo "\n[3] Testing Moved-Out Tenant with PENDING Payment & Auto-Archiving on Approval...\n";

// Clean up tenant 1 to free up unit 2
$pdo->exec("DELETE FROM payments WHERE tenant_id = $tenant1Id");
$pdo->exec("DELETE FROM users WHERE id = $tenant1Id");

$pdo->prepare("
    INSERT INTO users (username, name, email, password_hash, role, rental_status, move_in_date, unit_id, is_active, is_archived)
    VALUES ('test_auto_arc3', 'Test Pending Moveout', 'test_arc3@example.com', 'hash123', 'tenant', 'active', '2026-03-01', 2, 1, 0)
")->execute();
$tenant3Id = (int)$pdo->lastInsertId();

// Add pending receipt payment
$pdo->prepare("
    INSERT INTO payments (tenant_id, unit_id, payment_type, billing_cycle, due_date, amount_due, amount_paid, payment_date, payment_method, receipt_filename, status)
    VALUES (:tid, 2, 'Rent', '2026-10-01', '2026-10-05', 3000.00, 3000.00, '2026-10-03', 'GCash', 'receipt_test3.jpg', 'pending')
")->execute([':tid' => $tenant3Id]);
$payment3Id = (int)$pdo->lastInsertId();

// 3A. Owner marks tenant as Moved Out
$markRes3 = TenantService::markMovedOut($pdo, $tenant3Id);
assertTest("markMovedOut returned pending_settlement due to pending receipt", $markRes3['status'] === 'pending_settlement');
assertTest("Tenant 3 is NOT archived yet", $markRes3['archived'] === false);

$checkT3Before = $pdo->query("SELECT is_active, is_archived, rental_status FROM users WHERE id = $tenant3Id")->fetch(PDO::FETCH_ASSOC);
assertTest("Tenant 3 marked moved_out", $checkT3Before['rental_status'] === 'moved_out');
assertTest("Tenant 3 is still active", (int)$checkT3Before['is_active'] === 1);

// 3B. Now landlord reviews and APPROVES the payment receipt via api/payment_api.php simulation
// Emulate verify_payment
$pdo->prepare("
    UPDATE payments 
    SET status = 'approved', 
        verified_by_landlord = 1, 
        amount_paid = 3000.00,
        verified_at = NOW() 
    WHERE id = :pid
")->execute([':pid' => $payment3Id]);

// Trigger auto-archive check (as done in verify_payment)
$settlement3 = TenantService::checkTenantSettlement($pdo, $tenant3Id);
assertTest("Tenant 3 settlement check after approval reports is_settled = true", $settlement3['is_settled'] === true);

$archivedSuccess = TenantService::archiveSettledTenant($pdo, $tenant3Id);
assertTest("TenantService::archiveSettledTenant returned true", $archivedSuccess === true);

$checkT3After = $pdo->query("SELECT is_active, is_archived, deleted_at, archived_unit_number, archived_monthly_rent, unit_id FROM users WHERE id = $tenant3Id")->fetch(PDO::FETCH_ASSOC);
assertTest("Tenant 3 is now is_active = 0", (int)$checkT3After['is_active'] === 0);
assertTest("Tenant 3 is now is_archived = 1", (int)$checkT3After['is_archived'] === 1);
assertTest("Tenant 3 deleted_at timestamp is set", !empty($checkT3After['deleted_at']));
assertTest("Tenant 3 archived_unit_number is preserved", $checkT3After['archived_unit_number'] === 'Unit 2');
assertTest("Tenant 3 unit_id released (NULL)", $checkT3After['unit_id'] === null);

// 3C. Verify Payment is REMOVED from Active Payment Verification
$activePaymentsT3 = $pdo->query("
    SELECT p.id FROM payments p
    JOIN users u ON p.tenant_id = u.id
    WHERE (u.is_archived = 0 OR u.is_archived IS NULL)
      AND u.is_active = 1
      AND u.deleted_at IS NULL
      AND p.tenant_id = $tenant3Id
")->fetchAll();
assertTest("Tenant 3 payments automatically removed from Active Payment Verification", count($activePaymentsT3) === 0);

// 3D. Verify Payment is PRESERVED in MySQL and Associated with Archived Tenant
$archivedPaymentsT3 = $pdo->query("
    SELECT id, payment_type, billing_cycle, amount_due, amount_paid, payment_date, payment_method, receipt_filename, status
    FROM payments
    WHERE tenant_id = $tenant3Id
")->fetchAll(PDO::FETCH_ASSOC);
assertTest("Tenant 3 payment preserved in database", count($archivedPaymentsT3) === 1);
assertTest("Preserved payment has correct billing cycle", $archivedPaymentsT3[0]['billing_cycle'] === '2026-10-01');
assertTest("Preserved payment has receipt filename", $archivedPaymentsT3[0]['receipt_filename'] === 'receipt_test3.jpg');
assertTest("Preserved payment has payment method", $archivedPaymentsT3[0]['payment_method'] === 'GCash');
assertTest("Preserved payment has approved status", $archivedPaymentsT3[0]['status'] === 'approved');


// -------------------------------------------------------------------------
// TEST 4: Direct Move-Out of Fully Settled Tenant
// Expected: Auto-archived immediately upon marking moved out!
// -------------------------------------------------------------------------
echo "\n[4] Testing Direct Move-Out of Fully Settled Tenant...\n";

// Let Tenant 2 pay their remaining balance
$pdo->prepare("UPDATE payments SET status = 'approved', amount_paid = 3000.00 WHERE tenant_id = :tid")->execute([':tid' => $tenant2Id]);

// Now mark moved out (or check settlement)
$markRes2Direct = TenantService::markMovedOut($pdo, $tenant2Id);
assertTest("markMovedOut for now-settled tenant returns success", $markRes2Direct['status'] === 'success');
assertTest("Tenant 2 is archived immediately", $markRes2Direct['archived'] === true);

$checkT2Final = $pdo->query("SELECT is_active, is_archived, unit_id FROM users WHERE id = $tenant2Id")->fetch(PDO::FETCH_ASSOC);
assertTest("Tenant 2 is_active = 0", (int)$checkT2Final['is_active'] === 0);
assertTest("Tenant 2 is_archived = 1", (int)$checkT2Final['is_archived'] === 1);
assertTest("Tenant 2 unit_id is NULL", $checkT2Final['unit_id'] === null);

// Payments removed from active verification
$activePaymentsT2Final = $pdo->query("
    SELECT p.id FROM payments p
    JOIN users u ON p.tenant_id = u.id
    WHERE (u.is_archived = 0 OR u.is_archived IS NULL)
      AND u.is_active = 1
      AND u.deleted_at IS NULL
      AND p.tenant_id = $tenant2Id
")->fetchAll();
assertTest("Tenant 2 payments removed from active verification list", count($activePaymentsT2Final) === 0);

// Clean up test tenants
$pdo->exec("DELETE FROM payments WHERE tenant_id IN ($tenant1Id, $tenant2Id, $tenant3Id)");
$pdo->exec("DELETE FROM users WHERE id IN ($tenant1Id, $tenant2Id, $tenant3Id)");

echo "\n======================================================================\n";
echo "ALL AUTOMATIC ARCHIVING & PAYMENT VERIFICATION TESTS PASSED (100%)\n";
echo "======================================================================\n";
