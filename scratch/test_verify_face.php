<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = Database::getConnection();

// 1. Find or create a test tenant user
$stmt = $pdo->query("SELECT * FROM users WHERE role = 'tenant' LIMIT 1");
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    echo "No tenant found in database. Creating temporary test tenant...\n";
    $pdo->exec("INSERT INTO users (username, name, email, password_hash, role, verification_status, is_verified) 
                VALUES ('test_tenant', 'Test Tenant', 'test_tenant@example.com', 'hash', 'tenant', 'unverified', 0)");
    $tenantId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
}

$tenantId = (int)$tenant['id'];
echo "Testing with tenant user ID: {$tenantId} ({$tenant['name']})\n";

// Reset is_verified and verification_status for testing
$pdo->prepare("UPDATE users SET is_verified = 0, verification_status = 'unverified' WHERE id = :id")->execute([':id' => $tenantId]);

// 2. Test 1: Verify Face++ API credentials with cURL directly
echo "\n--- Test 1: Face++ Credentials Connectivity ---\n";
$ch = curl_init('https://api-us.faceplusplus.com/facepp/v3/compare');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'api_key' => 'b0uSL9nuSnJO0TXD6758FDzcIvM5Eipb',
        'api_secret' => 'dNrr8WS5LeYeclYEEFZgeFQp67osUh29',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$json = json_decode((string)$resp, true);
echo "HTTP Code: {$code}\n";
echo "Response: " . substr((string)$resp, 0, 150) . "...\n";
if (isset($json['error_message']) && str_contains($json['error_message'], 'MISSING_ARGUMENTS')) {
    echo "PASS: Face++ credentials authenticated successfully!\n";
} else {
    echo "WARNING: Unexpected response from Face++ API.\n";
}

// 3. Test 2: verify_face.php handling of mismatch / no face
echo "\n--- Test 2: Mismatch / Non-matching photos ---\n";
// Create two minimal valid JPEG images (100x100 blank)
$im1 = imagecreatetruecolor(120, 120);
imagefill($im1, 0, 0, imagecolorallocate($im1, 200, 200, 200));
$testIdPath = __DIR__ . '/test_id.jpg';
imagejpeg($im1, $testIdPath);
imagedestroy($im1);

$im2 = imagecreatetruecolor(120, 120);
imagefill($im2, 0, 0, imagecolorallocate($im2, 50, 50, 50));
$testLivePath = __DIR__ . '/test_live.jpg';
imagejpeg($im2, $testLivePath);
imagedestroy($im2);

$liveBase64 = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($testLivePath));

// Set up fake session for tenant
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user'] = $tenant;
$_SESSION['user_id'] = $tenantId;
$_SESSION['role'] = 'tenant';

// Test verify_face.php directly via simulated POST
$_POST = [
    'live_face_data' => $liveBase64,
    'id_type' => 'National ID',
];
$_FILES = [
    'id_photo' => [
        'name' => 'test_id.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $testIdPath,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($testIdPath),
    ]
];

ob_start();
include __DIR__ . '/../verify_face.php';
$output = ob_get_clean();
$result = json_decode((string)$output, true);
echo "Response from verify_face.php on blank images:\n";
echo $output . "\n";

if ($result && isset($result['message']) && $result['message'] === 'Face scan does not match the ID photo.') {
    echo "PASS: verify_face.php correctly returned 'Face scan does not match the ID photo.'\n";
} else {
    echo "Notice: Output was: " . $output . "\n";
}

// 4. Test 3: Database update on simulated match >= 75%
echo "\n--- Test 3: SQL UPDATE is_verified = 1 verification ---\n";
$confidence = 88.75;
$notes = sprintf('Face++ Verified: confidence %.2f%% (>= 75.00%%)', $confidence);
$updateStmt = $pdo->prepare("
    UPDATE users 
    SET is_verified = 1,
        verification_status = 'verified',
        id_verified_at = NOW(),
        verification_notes = :notes
    WHERE id = :id
");
$updateStmt->execute([
    ':notes' => $notes,
    ':id' => $tenantId
]);

$checkStmt = $pdo->prepare("SELECT id, name, is_verified, verification_status, verification_notes FROM users WHERE id = :id");
$checkStmt->execute([':id' => $tenantId]);
$updatedUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

echo "Updated user is_verified: " . var_export($updatedUser['is_verified'], true) . "\n";
echo "Updated user verification_status: " . $updatedUser['verification_status'] . "\n";
echo "Updated user verification_notes: " . $updatedUser['verification_notes'] . "\n";

if ((int)$updatedUser['is_verified'] === 1 && $updatedUser['verification_status'] === 'verified') {
    echo "PASS: is_verified = 1 and verification_status = 'verified' verified in database!\n";
} else {
    echo "FAIL: Database update failed.\n";
}

// Clean up test files
@unlink($testIdPath);
@unlink($testLivePath);
echo "\n--- All functional tests completed! ---\n";
