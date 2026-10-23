<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/verification_service.php';

echo "=== TESTING FACE++ CONFIDENCE THRESHOLDS & IMAGE ROUTING ===\n\n";

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

// Ensure test tenant exists
$stmt = $pdo->query("SELECT * FROM users WHERE role = 'tenant' LIMIT 1");
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    $pdo->exec("INSERT INTO users (username, name, email, password_hash, role, verification_status, is_verified) 
                VALUES ('test_thresh_user', 'Test Thresh User', 'testthresh@example.com', 'hash', 'tenant', 'unverified', 0)");
    $tenantId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
}

$tenantId = (int)$tenant['id'];

// Create sample ID and static face files
$uploadDir = __DIR__ . '/../uploads/id_verifications';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$idPath = $uploadDir . "/id_user{$tenantId}_test_thresh.jpg";
$liveStaticPath = $uploadDir . "/face_user{$tenantId}_test_static.jpg";

$im = imagecreatetruecolor(80, 80);
imagejpeg($im, $idPath);
imagejpeg($im, $liveStaticPath);
imagedestroy($im);

$liveBase64 = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($liveStaticPath));

// --- TEST SUITE 1: Face++ Compare with Confidence >= 70.0% ---
echo "--- TEST SUITE 1: Confidence Evaluation with Realistic Baseline (>= 70%) ---\n";

// Helper to run verify_face via run_subtest.php
function runVerifyTest(string $arg): array {
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/run_subtest.php') . ' ' . escapeshellarg($arg);
    $output = shell_exec($cmd);
    return json_decode((string)$output, true) ?: ['raw' => $output];
}

// 1.1 Confidence 70.0% (Baseline requirement: >= 70% accepted)
$pdo->prepare("UPDATE users SET is_verified = 0, verification_status = 'unverified' WHERE id = :id")->execute([':id' => $tenantId]);

$res70 = runVerifyTest('70.0');
assertTest("Confidence 70.0% explicitly accepted as valid match (status = success)", isset($res70['status']) && $res70['status'] === 'success');
assertTest("Confidence 70.0% returns is_verified = 1", isset($res70['is_verified']) && (int)$res70['is_verified'] === 1);
assertTest("Confidence 70.0% returns clean success message 'Verification Successful'", ($res70['message'] ?? '') === 'Verification Successful');

$checkUser = $pdo->prepare("SELECT is_verified, verification_status, verification_notes, live_photo_filename FROM users WHERE id = :id");
$checkUser->execute([':id' => $tenantId]);
$dbUser = $checkUser->fetch(PDO::FETCH_ASSOC);

assertTest("Database updated to is_verified = 1 and verification_status = 'verified'", (int)$dbUser['is_verified'] === 1 && $dbUser['verification_status'] === 'verified');
assertTest("Verification notes record baseline >= 70.00%", str_contains($dbUser['verification_notes'] ?? '', 'baseline >= 70.00%'));

// 1.2 Confidence 71.5% (Typical Face++ match that previously failed under >= 75%)
$pdo->prepare("UPDATE users SET is_verified = 0, verification_status = 'unverified' WHERE id = :id")->execute([':id' => $tenantId]);
$res71 = runVerifyTest('71.5');
assertTest("Confidence 71.5% explicitly accepted as valid match", isset($res71['status']) && $res71['status'] === 'success');

// 1.3 Confidence 69.5% meeting Face++ API 1e-4 threshold (69.1)
$pdo->prepare("UPDATE users SET is_verified = 0, verification_status = 'unverified' WHERE id = :id")->execute([':id' => $tenantId]);
$res69 = runVerifyTest('69.5');
assertTest("Confidence 69.5% meeting 1e-4 threshold (69.1) is accepted", isset($res69['status']) && $res69['status'] === 'success');

// 1.4 Confidence 52.0% (Genuine mismatch below threshold)
$pdo->prepare("UPDATE users SET is_verified = 0, verification_status = 'unverified' WHERE id = :id")->execute([':id' => $tenantId]);
$res52 = runVerifyTest('52.0');
assertTest("Confidence 52.0% rejected with 422 error", isset($res52['status']) && $res52['status'] === 'error');
assertTest("Rejection message indicates face scan does not match", str_contains($res52['message'] ?? '', 'Face scan does not match'));

$checkUser->execute([':id' => $tenantId]);
$dbUserFail = $checkUser->fetch(PDO::FETCH_ASSOC);
assertTest("Database is_verified remains 0 on rejection", (int)$dbUserFail['is_verified'] === 0 && $dbUserFail['verification_status'] !== 'verified');

// --- TEST SUITE 2: VerificationService::compareFacesWithFacePlusPlus ---
echo "\n--- TEST SUITE 2: VerificationService Threshold Evaluation ---\n";

$_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE'] = '70.2';
$svcRes70 = VerificationService::compareFacesWithFacePlusPlus($idPath, $liveStaticPath);
assertTest("VerificationService accepts confidence 70.2% as match", $svcRes70['success'] === true && $svcRes70['is_match'] === true);
assertTest("VerificationService returns threshold_1e4 in payload", isset($svcRes70['threshold_1e4']) && $svcRes70['threshold_1e4'] > 0);

$_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE'] = '45.0';
$svcRes45 = VerificationService::compareFacesWithFacePlusPlus($idPath, $liveStaticPath);
assertTest("VerificationService rejects confidence 45.0% as non-match", $svcRes45['success'] === true && $svcRes45['is_match'] === false);

unset($_SERVER['HTTP_X_MOCK_FACEPP_CONFIDENCE']);

// --- TEST SUITE 3: Image Payload Routing & Phase 3 Decoupling ---
echo "\n--- TEST SUITE 3: Image Payload Routing & Phase 3 Decoupling ---\n";

$portalContent = file_get_contents(__DIR__ . '/../tenant_portal.php');

assertTest("Phase 2 saves static capture to capturedFaceDataUrl and capturedFaceBlob", 
    str_contains($portalContent, 'capturedFaceDataUrl = canvas.toDataURL') &&
    str_contains($portalContent, 'capturedFaceBlob = blob')
);

assertTest("Phase 3 strictly uses facial landmarks for blink & head turn without capturing photos",
    str_contains($portalContent, 'executeLivenessDetectionStep') &&
    str_contains($portalContent, 'TinyFaceDetectorOptions') &&
    str_contains($portalContent, 'withFaceLandmarks')
);

assertTest("completeLivenessVerification strictly routes Phase 2 static photo to backend",
    str_contains($portalContent, "formData.append('live_face_data', capturedFaceDataUrl)") &&
    str_contains($portalContent, "formData.append('live_face', capturedFaceBlob")
);

assertTest("verify_face.php stores clean static photo as live_photo_filename in database",
    str_contains(file_get_contents(__DIR__ . '/../verify_face.php'), 'live_photo_filename = :live_face')
);

echo "\n========================================================================\n";
echo "SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================================\n";

if ($failCount > 0) exit(1);
exit(0);
