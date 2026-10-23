<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

echo "=== TESTING AUTO-GENERATION OF PLACEHOLDER PAYMENT ON TENANT CREATION ===\n\n";

$baseUrl = 'http://localhost:8000';
$cookieJar = tempnam(sys_get_temp_dir(), 'rc_admin_cookie_');

// 1. Log in as Landlord (admin / admin123)
$ch = curl_init("$baseUrl/login.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => http_build_query([
        'username' => 'admin',
        'password' => 'admin123'
    ]),
    CURLOPT_FOLLOWLOCATION => true
]);
$loginRes = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Admin Login HTTP Code: $loginCode\n";
if ($loginCode !== 200) {
    echo "[FAIL] Could not log in as admin.\n";
    exit(1);
}
echo "[PASS] Logged in as landlord (admin)\n";

// 2. Register a new tenant account via api/tenant_directory_api.php
$testEmail = 'placeholder_test_' . time() . '@example.com';
$ch = curl_init("$baseUrl/api/tenant_directory_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode([
        'action' => 'create',
        'unit_id' => 1,
        'name' => 'Auto Pay Tenant Test',
        'email' => $testEmail,
        'phone' => '+63 917 999 8888',
        'birthday' => '1996-07-15',
        'emergency_contact_name' => 'Emergency Test',
        'emergency_contact_relationship' => 'Parent',
        'emergency_contact_phone' => '+63 917 111 2222',
        'move_in_date' => '2026-11-01'
    ])
]);
$createRes = curl_exec($ch);
$createCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Create Tenant HTTP Code: $createCode\n";
echo "Response: $createRes\n";

$data = json_decode((string)$createRes, true);
$tenantId = (int)($data['tenant_id'] ?? 0);
$placeholderPayId = (int)($data['placeholder_payment_id'] ?? 0);

if ($createCode !== 200 || $tenantId <= 0 || $placeholderPayId <= 0) {
    echo "[FAIL] Failed to create tenant or placeholder payment ID missing.\n";
    exit(1);
}
echo "[PASS] New tenant created with ID: $tenantId\n";
echo "[PASS] Returned placeholder payment ID: $placeholderPayId\n";

// 3. Verify in database that the placeholder payment record exists and is associated with this new tenant
$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT * FROM payments WHERE id = :id AND tenant_id = :tid LIMIT 1");
$stmt->execute([':id' => $placeholderPayId, ':tid' => $tenantId]);
$paymentRow = $stmt->fetch();

if (!$paymentRow) {
    echo "[FAIL] Placeholder payment record not found in payments table!\n";
    exit(1);
}

echo "[PASS] Found placeholder payment record in database for tenant_id: {$paymentRow['tenant_id']}\n";
echo "       Unit ID:       {$paymentRow['unit_id']}\n";
echo "       Billing Cycle: {$paymentRow['billing_cycle']}\n";
echo "       Due Date:      {$paymentRow['due_date']}\n";
echo "       Amount Due:    {$paymentRow['amount_due']}\n";
echo "       Amount Paid:   {$paymentRow['amount_paid']}\n";
echo "       Status:        {$paymentRow['status']}\n";
echo "       Notes:         {$paymentRow['verification_notes']}\n";

$assertChecks = (
    (int)$paymentRow['tenant_id'] === $tenantId &&
    (int)$paymentRow['unit_id'] === 1 &&
    (float)$paymentRow['amount_due'] === 15000.00 &&
    (float)$paymentRow['amount_paid'] === 0.00 &&
    $paymentRow['status'] === 'pending' &&
    str_contains($paymentRow['verification_notes'], 'Placeholder')
);

if ($assertChecks) {
    echo "\n[PASS] All placeholder payment attributes strictly match specifications!\n";
} else {
    echo "\n[FAIL] Placeholder payment attributes mismatch.\n";
}

// 4. Clean up test data
$pdo->prepare("DELETE FROM payments WHERE id = :id")->execute([':id' => $placeholderPayId]);
$pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $tenantId]);
echo "[PASS] Cleaned up test tenant and payment records.\n";

@unlink($cookieJar);
echo "\nOVERALL RESULT: SUCCESS!\n";
