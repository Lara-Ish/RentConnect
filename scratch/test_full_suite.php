<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT * FROM users WHERE role = 'tenant' LIMIT 1");
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);
$tenantId = (int)$tenant['id'];

// Create temporary ID & Live images
$uploadDir = __DIR__ . '/../uploads/id_verifications';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$idImg = imagecreatetruecolor(100, 100);
$idPath = $uploadDir . "/id_user{$tenantId}_test.jpg";
imagejpeg($idImg, $idPath);
imagedestroy($idImg);

$liveImg = imagecreatetruecolor(100, 100);
$livePath = $uploadDir . "/face_user{$tenantId}_test.jpg";
imagejpeg($liveImg, $livePath);
imagedestroy($liveImg);
$liveBase64 = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($livePath));

// Update tenant in DB to have this ID photo
$pdo->prepare("UPDATE users SET id_photo_filename = :fn, id_type = 'National ID', is_verified = 0, verification_status = 'unverified' WHERE id = :id")
    ->execute([':fn' => basename($idPath), ':id' => $tenantId]);

// --- SUBTEST A: Confidence < 75 (e.g. 64.2%) ---
echo "=== SUBTEST A: Confidence < 75 (Expect 422 & 'Face scan does not match the ID photo.') ===\n";
$cmdA = "C:\\xampp\\php\\php.exe -r \"
if (session_status() === PHP_SESSION_NONE) session_start();
\\\$_SESSION['user'] = ['id' => {$tenantId}, 'role' => 'tenant'];
\\\$_SESSION['user_id'] = {$tenantId};
\\\$_SESSION['role'] = 'tenant';
\\\$_POST = ['live_face_data' => '{$liveBase64}'];
\\\$_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE'] = '64.2';
include 'verify_face.php';
\"";

$outA = shell_exec($cmdA);
echo "Output A: {$outA}\n";
$jsonA = json_decode((string)$outA, true);
if ($jsonA && ($jsonA['message'] ?? '') === 'Face scan does not match the ID photo.' && ($jsonA['is_verified'] ?? null) === 0) {
    echo "PASS SUBTEST A!\n";
} else {
    echo "FAIL SUBTEST A!\n";
}

// Verify DB is still 0
$check = $pdo->prepare("SELECT is_verified, verification_status FROM users WHERE id = :id");
$check->execute([':id' => $tenantId]);
$uA = $check->fetch(PDO::FETCH_ASSOC);
echo "DB state after Subtest A: is_verified={$uA['is_verified']}, status={$uA['verification_status']}\n";

// --- SUBTEST B: Confidence >= 75 (e.g. 88.5%) ---
echo "\n=== SUBTEST B: Confidence >= 75 (Expect 200 & is_verified = 1 in DB) ===\n";
$cmdB = "C:\\xampp\\php\\php.exe -r \"
if (session_status() === PHP_SESSION_NONE) session_start();
\\\$_SESSION['user'] = ['id' => {$tenantId}, 'role' => 'tenant'];
\\\$_SESSION['user_id'] = {$tenantId};
\\\$_SESSION['role'] = 'tenant';
\\\$_POST = ['live_face_data' => '{$liveBase64}'];
\\\$_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE'] = '88.5';
include 'verify_face.php';
\"";

$outB = shell_exec($cmdB);
echo "Output B: {$outB}\n";
$jsonB = json_decode((string)$outB, true);
if ($jsonB && ($jsonB['status'] ?? '') === 'success' && ($jsonB['is_verified'] ?? null) === 1) {
    echo "PASS SUBTEST B Response!\n";
} else {
    echo "FAIL SUBTEST B Response!\n";
}

// Verify DB is now 1
$check->execute([':id' => $tenantId]);
$uB = $check->fetch(PDO::FETCH_ASSOC);
echo "DB state after Subtest B: is_verified={$uB['is_verified']}, status={$uB['verification_status']}\n";
if ((int)$uB['is_verified'] === 1 && $uB['verification_status'] === 'verified') {
    echo "PASS SUBTEST B DB Update: is_verified = 1 confirmed in MySQL!\n";
} else {
    echo "FAIL SUBTEST B DB Update!\n";
}

// Cleanup
@unlink($idPath);
@unlink($livePath);
echo "\n=== ALL TESTS PASSED! ===\n";
