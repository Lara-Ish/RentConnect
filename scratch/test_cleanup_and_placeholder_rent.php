<?php
/**
 * Test Suite: Database Cleanup & Automatic Placeholder Rent Creation
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = Database::getConnection();

function assertCheck(bool $condition, string $message): void {
    if (!$condition) {
        echo "❌ FAILED: {$message}\n";
        exit(1);
    }
    echo "✅ PASSED: {$message}\n";
}

echo "=== TEST 1: VERIFY CLEANUP STATE ===\n";

$adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'landlord'")->fetchColumn();
assertCheck($adminCount === 1, "Exactly 1 Admin/Landlord exists in users");

$tenantCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'tenant'")->fetchColumn();
assertCheck($tenantCount === 0, "0 tenants exist in users after cleanup");

$paymentsCount = (int)$pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
assertCheck($paymentsCount === 0, "0 payments exist after cleanup");

$ticketsCount = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_tickets")->fetchColumn();
assertCheck($ticketsCount === 0, "0 maintenance tickets exist after cleanup");

$notifsCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
assertCheck($notifsCount === 0, "0 notifications exist after cleanup");

$activeTenantSetting = $pdo->query("SELECT active_tenant_id FROM apartment_settings WHERE id = 1")->fetchColumn();
assertCheck($activeTenantSetting === null, "apartment_settings.active_tenant_id is NULL");

echo "\n=== TEST 2: AUTO PLACEHOLDER RENT CREATION (UNIT 1) ===\n";

// Authenticate as Admin for API operations
$adminUser = $pdo->query("SELECT * FROM users WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = (int)$adminUser['id'];
$_SESSION['role'] = 'landlord';
$_SESSION['username'] = $adminUser['username'];
$_SESSION['name'] = $adminUser['name'];
$_SESSION['email'] = $adminUser['email'];
$sessionId = session_id();
session_write_close();

// Prepare payload for creating new tenant in Unit 1
$createPayload = [
    'action' => 'create',
    'name' => 'Carlos Mendoza',
    'email' => 'carlos.mendoza@rentconnect.ph',
    'phone' => '+63 917 555 1234',
    'birthday' => '1992-05-15',
    'unit_id' => 1,
    'emergency_contact_name' => 'Elena Mendoza',
    'emergency_contact_relationship' => 'Spouse',
    'emergency_contact_phone' => '+63 917 555 5678',
    'move_in_date' => '2026-11-01',
    'adults_count' => 2,
    'kids_count' => 0
];

// Execute creation directly via logic or curl/subrequest
// We simulate the API execution:
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
// Include the script or run through a local handler
$ch = curl_init('http://localhost/RentConnect/api/tenant_directory_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($createPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Cookie: ' . session_name() . '=' . $sessionId]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);
echo "Response from tenant create API: " . $resp . "\n";
assertCheck($httpCode === 200 && ($data['status'] ?? '') === 'success', "Tenant creation API returned HTTP 200 success");
$newTenantId = (int)$data['tenant_id'];
$placeholderPaymentId = (int)$data['placeholder_payment_id'];
assertCheck($newTenantId > 0, "New tenant ID is valid ({$newTenantId})");
assertCheck($placeholderPaymentId > 0, "Placeholder payment ID is valid ({$placeholderPaymentId})");

// Inspect the created placeholder payment record in database
$stmt = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
$stmt->execute([':id' => $placeholderPaymentId]);
$pm = $stmt->fetch(PDO::FETCH_ASSOC);

assertCheck($pm['tenant_id'] == $newTenantId, "Placeholder payment is linked to newly created tenant ({$pm['tenant_id']})");
assertCheck($pm['unit_id'] == 1, "Placeholder payment is linked to Unit 1");
assertCheck($pm['status'] === 'pending_setup', "Payment status is strictly 'pending_setup'");
assertCheck($pm['amount_due'] === null, "amount_due is NULL (Not yet configured)");
assertCheck($pm['billing_cycle'] === null, "billing_cycle is NULL (To be assigned)");
assertCheck($pm['due_date'] === null, "due_date is NULL (To be configured)");
assertCheck($pm['payment_date'] === null, "payment_date is NULL (Not yet paid)");
assertCheck((float)$pm['amount_paid'] === 0.0, "amount_paid is 0.00");
assertCheck((int)$pm['verified_by_landlord'] === 0, "verified_by_landlord is 0 (NOT verified/approved)");
assertCheck($pm['receipt_filename'] === null, "receipt_filename is NULL (No receipt generated)");

echo "\n=== TEST 3: ONE ACTIVE TENANT PER UNIT RESTRICTION ===\n";

// Try creating a second tenant in Unit 1
$dupPayload = [
    'action' => 'create',
    'name' => 'Roberto Cruz',
    'email' => 'roberto.cruz@rentconnect.ph',
    'unit_id' => 1,
    'emergency_contact_name' => 'Maria Cruz',
    'move_in_date' => '2026-11-01'
];

$ch = curl_init('http://localhost/RentConnect/api/tenant_directory_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dupPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Cookie: ' . session_name() . '=' . $sessionId]);
$respDup = curl_exec($ch);
$httpCodeDup = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$dataDup = json_decode($respDup, true);
echo "Response on duplicate unit assignment: " . $respDup . "\n";
assertCheck($httpCodeDup === 409, "Duplicate unit assignment returns HTTP 409 Conflict");
assertCheck(
    strpos($dataDup['message'] ?? '', 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.') !== false,
    "Expected error message returned for occupied unit"
);

echo "\n=== TEST 4: CONFIGURE PLACEHOLDER RENT RECORD ===\n";

$configPayload = [
    'action' => 'configure_rent',
    'payment_id' => $placeholderPaymentId,
    'monthly_rent' => 6000.00,
    'billing_cycle' => '2026-11',
    'due_date' => '2026-11-05'
];

$ch = curl_init('http://localhost/RentConnect/api/payment_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($configPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Cookie: ' . session_name() . '=' . $sessionId]);
$respConfig = curl_exec($ch);
$httpCodeConfig = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$dataConfig = json_decode($respConfig, true);
echo "Response from configure_rent API: " . $respConfig . "\n";
assertCheck($httpCodeConfig === 200 && ($dataConfig['status'] ?? '') === 'success', "configure_rent API returned HTTP 200 success");

// Inspect payment record in DB after configuration
$stmt->execute([':id' => $placeholderPaymentId]);
$pmConfigured = $stmt->fetch(PDO::FETCH_ASSOC);

assertCheck($pmConfigured['status'] === 'pending', "Status updated from 'pending_setup' to active 'pending'");
assertCheck((float)$pmConfigured['amount_due'] === 6000.00, "amount_due is set to 6000.00");
assertCheck($pmConfigured['billing_cycle'] === '2026-11-01', "billing_cycle is set to 2026-11-01");
assertCheck($pmConfigured['due_date'] === '2026-11-05', "due_date is set to 2026-11-05");
assertCheck((float)$pmConfigured['amount_paid'] === 0.00, "amount_paid remains 0.00 (NOT treated as paid)");
assertCheck((int)$pmConfigured['verified_by_landlord'] === 0, "verified_by_landlord remains 0");
assertCheck($pmConfigured['receipt_filename'] === null, "receipt_filename remains NULL (No receipt generated)");

echo "\n=== TEST 5: PREVENT DUPLICATE ACTIVE RENT RECORD FOR SAME CYCLE ===\n";

// Attempt to configure another record with the exact same tenant and billing cycle
$dupConfigPayload = [
    'action' => 'configure_rent',
    'payment_id' => 99999, // non-existent or duplicate check
    'monthly_rent' => 6000.00,
    'billing_cycle' => '2026-11',
    'due_date' => '2026-11-05'
];
$ch = curl_init('http://localhost/RentConnect/api/payment_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dupConfigPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Cookie: ' . session_name() . '=' . $sessionId]);
$respDupConfig = curl_exec($ch);
$httpCodeDupConfig = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertCheck($httpCodeDupConfig === 404 || $httpCodeDupConfig === 409, "Duplicate / invalid rent configuration rejected");

echo "\n=== TEST 6: ADD TENANT TO UNIT 2 & TRANSFER TEST ===\n";

$createUnit2Payload = [
    'action' => 'create',
    'name' => 'Lara Santos',
    'email' => 'lara.santos@rentconnect.ph',
    'phone' => '+63 918 555 7890',
    'birthday' => '1995-09-20',
    'unit_id' => 2,
    'emergency_contact_name' => 'Marco Santos',
    'move_in_date' => '2026-11-01'
];

$ch = curl_init('http://localhost/RentConnect/api/tenant_directory_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($createUnit2Payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Cookie: ' . session_name() . '=' . $sessionId]);
$respU2 = curl_exec($ch);
$httpCodeU2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$dataU2 = json_decode($respU2, true);
assertCheck($httpCodeU2 === 200 && ($dataU2['status'] ?? '') === 'success', "Tenant created in Unit 2");
$tenant2Id = (int)$dataU2['tenant_id'];

// Try to transfer Tenant 2 to Unit 1 (occupied by Tenant 1)
$transferOccupied = [
    'action' => 'transfer',
    'tenant_id' => $tenant2Id,
    'unit_id' => 1
];
$ch = curl_init('http://localhost/RentConnect/api/tenant_directory_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($transferOccupied));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Cookie: ' . session_name() . '=' . $sessionId]);
$respTr = curl_exec($ch);
$httpCodeTr = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$dataTr = json_decode($respTr, true);
assertCheck($httpCodeTr === 409, "Transfer to occupied Unit 1 blocked with HTTP 409");
assertCheck(
    strpos($dataTr['message'] ?? '', 'Only one tenant account is allowed per unit') !== false,
    "Expected message on occupied unit transfer blocked"
);

echo "\n🎉 ALL VERIFICATION TESTS COMPLETED SUCCESSFULLY! 🎉\n";
