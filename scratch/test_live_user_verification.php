<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

echo "=== TESTING REAL-WORLD TENANT VERIFICATION WITHOUT GEMINI_API_KEY ===\n\n";

$cookieJar = tempnam(sys_get_temp_dir(), 'rc_cookie_');
$baseUrl = 'http://localhost:8000';

// 1. Login as tenant1
$ch = curl_init("$baseUrl/login.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => http_build_query([
        'username' => 'tenant1',
        'password' => '111111'
    ]),
    CURLOPT_FOLLOWLOCATION => true
]);
$loginRes = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login Response HTTP Code: $loginCode\n";

$lenaPath = __DIR__ . '/lena.jpg';

// Post verification without ANY mock headers or GEMINI_API_KEY
$ch = curl_init("$baseUrl/api/profile_api.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POSTFIELDS => [
        'action' => 'verify_account',
        'id_type' => "Driver's License",
        'id_photo' => new CURLFile($lenaPath, 'image/jpeg', 'my_id_card.jpg'),
        'live_face' => new CURLFile($lenaPath, 'image/jpeg', 'live_webcam_face.jpg'),
    ]
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Verification Response (HTTP $code):\n$res\n\n";

$data = json_decode((string)$res, true);
if ($code === 200 && ($data['verification_status'] ?? '') === 'verified') {
    echo " [PASS] Verification succeeded without requiring GEMINI_API_KEY!\n";
} else {
    echo " [FAIL] Verification did not succeed: " . ($data['message'] ?? 'Unknown error') . "\n";
}

// Reset tenant1 for user demo
$pdo = Database::getConnection();
$pdo->exec("UPDATE users SET verification_status = 'unverified', id_type = NULL, id_photo_filename = NULL, live_photo_filename = NULL, id_submitted_at = NULL, id_verified_at = NULL, verification_notes = NULL WHERE username = 'tenant1'");

@unlink($cookieJar);
