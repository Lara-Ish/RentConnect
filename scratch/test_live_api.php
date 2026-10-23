<?php
declare(strict_types=1);

$baseUrl = 'http://localhost:8000';

echo "=== TESTING LIVE WEB APIS ===\n";

// 1. Test Login as Admin (Landlord) via login.php
$ch = curl_init("$baseUrl/login.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => 'admin', 'password' => 'admin123']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
curl_close($ch);

echo "Admin Login HTTP: $httpCode\n";

// Extract session cookie
preg_match('/Set-Cookie:\s*([^;]+)/mi', $headers, $matches);
$sessionCookie = $matches[1] ?? '';
echo "Session Cookie: $sessionCookie\n";

if (!$sessionCookie) {
    echo "FAILED to get session cookie\n";
    exit(1);
}

// 2. Test Profile API as Admin
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIE => $sessionCookie
]);
$profRes = curl_exec($ch);
echo "Profile API GET: $profRes\n";
curl_close($ch);

// 3. Test Tenant Creation API with auto-generated credentials
$ch = curl_init("$baseUrl/api/tenant_directory_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'action' => 'create',
        'unit_id' => 3,
        'name' => 'Kobe Bryant',
        'birthday' => '1995-08-23',
        'email' => 'kobe.bryant.' . time() . '@rentconnect.ph',
        'phone' => '+63 919 888 7777',
        'emergency_contact_name' => 'Vanessa Bryant',
        'emergency_contact_relationship' => 'Spouse',
        'emergency_contact_phone' => '+63 919 777 6666'
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIE => $sessionCookie
]);
$createRes = curl_exec($ch);
echo "Tenant Creation API: $createRes\n";
$createdData = json_decode($createRes, true);
curl_close($ch);

if (!empty($createdData['credentials'])) {
    $creds = $createdData['credentials'];
    echo "\nSUCCESS: Auto-generated credentials:\n";
    echo "  Username: {$creds['username']}\n";
    echo "  Password: {$creds['password']}\n";
    echo "  Name: {$creds['name']}\n";
    echo "  Unit: {$creds['unit_number']}\n";

    // Clean up created tenant
    $newId = $createdData['tenant_id'];
    $ch = curl_init("$baseUrl/api/tenant_directory_api.php");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['action' => 'delete', 'tenant_id' => $newId]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIE => $sessionCookie
    ]);
    $delRes = curl_exec($ch);
    echo "Cleanup Tenant: $delRes\n";
    curl_close($ch);
} else {
    echo "ERROR: Credentials not returned\n";
}

echo "\nALL LIVE API TESTS COMPLETE!\n";
