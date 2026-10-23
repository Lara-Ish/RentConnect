<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT * FROM users WHERE role = 'tenant' LIMIT 1");
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    $pdo->exec("INSERT INTO users (username, name, email, password_hash, role, verification_status, is_verified) 
                VALUES ('unit_subtest_user', 'Unit Subtest User', 'subtest@example.com', 'hash', 'tenant', 'unverified', 0)");
    $tenantId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
}

$tenantId = (int)$tenant['id'];

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

// Ensure ID filename is set in user record
$pdo->prepare("UPDATE users SET id_photo_filename = :fn, id_type = 'National ID', is_verified = 0, verification_status = 'unverified' WHERE id = :id")
    ->execute([':fn' => basename($idPath), ':id' => $tenantId]);

$testType = $argv[1] ?? 'low';

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user'] = ['id' => $tenantId, 'role' => 'tenant', 'name' => $tenant['name']];
$_SESSION['user_id'] = $tenantId;
$_SESSION['role'] = 'tenant';

$_POST['action'] = 'complete_verification';
$_POST['live_face_data'] = $liveBase64;
$_POST['id_type'] = 'National ID';
$_POST['liveness_passed'] = '1';

if ($testType === 'empty_faces2') {
    $_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE'] = '88.5';
    $_SERVER['HTTP_X_MOCK_FACEPP_EMPTY_FACES2'] = '1';
} elseif (is_numeric($testType)) {
    $_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE'] = (string)$testType;
} elseif ($testType === 'high') {
    $_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE'] = '88.5';
} else {
    $_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE'] = '42.5';
}

require __DIR__ . '/../verify_face.php';
