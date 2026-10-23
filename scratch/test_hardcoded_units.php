<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

echo "=== TESTING HARDCODED UNIT CONFIGURATIONS & PRICING ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $desc, bool $cond): void {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] {$desc}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failCount++;
    }
}

$pdo = Database::getConnection();

// --- TEST 1: Database units table content ---
$stmt = $pdo->query("SELECT id, unit_number, bedrooms, monthly_rent FROM units ORDER BY id ASC");
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

assertTest("Exactly 3 units exist in database", count($units) === 3);

$u1 = $units[0] ?? [];
assertTest("Unit 1 is named 'Unit 1'", ($u1['unit_number'] ?? '') === 'Unit 1');
assertTest("Unit 1 has 1 Bedroom", (int)($u1['bedrooms'] ?? 0) === 1);
assertTest("Unit 1 has Monthly Rent ₱6,000", (float)($u1['monthly_rent'] ?? 0) === 6000.00);

$u2 = $units[1] ?? [];
assertTest("Unit 2 is named 'Unit 2'", ($u2['unit_number'] ?? '') === 'Unit 2');
assertTest("Unit 2 has 2 Bedrooms", (int)($u2['bedrooms'] ?? 0) === 2);
assertTest("Unit 2 has Monthly Rent ₱8,000", (float)($u2['monthly_rent'] ?? 0) === 8000.00);

$u3 = $units[2] ?? [];
assertTest("Unit 3 is named 'Unit 3'", ($u3['unit_number'] ?? '') === 'Unit 3');
assertTest("Unit 3 has 3 Bedrooms", (int)($u3['bedrooms'] ?? 0) === 3);
assertTest("Unit 3 has Monthly Rent ₱8,000", (float)($u3['monthly_rent'] ?? 0) === 8000.00);

// --- TEST 2: Registration & Auto-generated placeholder payment ---
function testRegisterTenant(int $unitId, string $emailSuffix): array {
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/run_reg_subtest.php') . ' ' . $unitId . ' ' . escapeshellarg($emailSuffix);
    $res = shell_exec($cmd);
    return json_decode((string)$res, true) ?: ['raw' => $res];
}

$suffix = time();

// Register to Unit 1
$res1 = testRegisterTenant(1, (string)$suffix);
assertTest("Registration to Unit 1 succeeds", isset($res1['status']) && $res1['status'] === 'success');
$tenantId1 = (int)($res1['tenant_id'] ?? 0);
$payId1 = (int)($res1['placeholder_payment_id'] ?? 0);

$stmtPay1 = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
$stmtPay1->execute([':id' => $payId1]);
$pay1 = $stmtPay1->fetch(PDO::FETCH_ASSOC);

assertTest("Unit 1 placeholder payment has amount_due = ₱6,000.00", (float)($pay1['amount_due'] ?? 0) === 6000.00);
assertTest("Unit 1 placeholder payment is pending with amount_paid = 0.00", ($pay1['status'] ?? '') === 'pending' && (float)($pay1['amount_paid'] ?? 1) === 0.00);

// Register to Unit 2
$res2 = testRegisterTenant(2, (string)$suffix);
assertTest("Registration to Unit 2 succeeds", isset($res2['status']) && $res2['status'] === 'success');
$tenantId2 = (int)($res2['tenant_id'] ?? 0);
$payId2 = (int)($res2['placeholder_payment_id'] ?? 0);

$stmtPay2 = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
$stmtPay2->execute([':id' => $payId2]);
$pay2 = $stmtPay2->fetch(PDO::FETCH_ASSOC);

assertTest("Unit 2 placeholder payment has amount_due = ₱8,000.00", (float)($pay2['amount_due'] ?? 0) === 8000.00);

// Register to Unit 3
$res3 = testRegisterTenant(3, (string)$suffix);
assertTest("Registration to Unit 3 succeeds", isset($res3['status']) && $res3['status'] === 'success');
$tenantId3 = (int)($res3['tenant_id'] ?? 0);
$payId3 = (int)($res3['placeholder_payment_id'] ?? 0);

$stmtPay3 = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
$stmtPay3->execute([':id' => $payId3]);
$pay3 = $stmtPay3->fetch(PDO::FETCH_ASSOC);

assertTest("Unit 3 placeholder payment has amount_due = ₱8,000.00", (float)($pay3['amount_due'] ?? 0) === 8000.00);

// Cleanup test tenants and payments
if ($tenantId1) {
    $pdo->prepare("DELETE FROM payments WHERE tenant_id = :id")->execute([':id' => $tenantId1]);
    $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $tenantId1]);
}
if ($tenantId2) {
    $pdo->prepare("DELETE FROM payments WHERE tenant_id = :id")->execute([':id' => $tenantId2]);
    $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $tenantId2]);
}
if ($tenantId3) {
    $pdo->prepare("DELETE FROM payments WHERE tenant_id = :id")->execute([':id' => $tenantId3]);
    $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $tenantId3]);
}

echo "\n========================================================================\n";
echo "SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================================\n";

if ($failCount > 0) exit(1);
exit(0);
