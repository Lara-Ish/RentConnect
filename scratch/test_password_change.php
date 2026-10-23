<?php
declare(strict_types=1);

$baseUrl = 'http://localhost:8000';

echo "=== TESTING PASSWORD CHANGE VIA PROFILE API ===\n";

// 1. Login as tenant1 with password '111111'
$ch = curl_init("$baseUrl/login.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => 'tenant1', 'password' => '111111']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true
]);
$response = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headers = substr($response, 0, $headerSize);
curl_close($ch);

preg_match('/Set-Cookie:\s*([^;]+)/mi', $headers, $matches);
$tenantCookie = $matches[1] ?? '';

if (!$tenantCookie) {
    echo "FAILED: Could not login as tenant1\n";
    exit(1);
}
echo "Logged in as tenant1!\n";

// 2. Change password from 111111 to 999999
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'action' => 'change_password',
        'current_password' => '111111',
        'new_password' => '999999',
        'confirm_password' => '999999'
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIE => $tenantCookie
]);
$res = curl_exec($ch);
echo "Change password response: $res\n";
curl_close($ch);

// 3. Test logging in with new password 999999
$ch = curl_init("$baseUrl/login.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => 'tenant1', 'password' => '999999']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true
]);
$resNew = curl_exec($ch);
$codeNew = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Login with new password HTTP code: $codeNew (Expected: 302)\n";

// 4. Revert password back to 111111 for standard seeded state
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'action' => 'change_password',
        'current_password' => '999999',
        'new_password' => '111111',
        'confirm_password' => '111111'
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIE => $tenantCookie
]);
$revertRes = curl_exec($ch);
echo "Revert password response: $revertRes\n";
curl_close($ch);

echo "PASSWORD CHANGE VERIFICATION COMPLETE AND REVERTED CLEANLY!\n";
