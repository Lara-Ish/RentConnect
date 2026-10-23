<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/credentials.php';
require_once __DIR__ . '/../includes/verification_service.php';

echo "=== TESTING COMPLETE TENANT VERIFICATION FLOW & STRICT REQUIREMENTS ===\n\n";

$allPassed = true;
function testAssert(string $name, bool $condition, string $detail = '') {
    global $allPassed;
    if ($condition) {
        echo " [PASS] $name\n";
    } else {
        echo " [FAIL] $name: $detail\n";
        $allPassed = false;
    }
}

// -----------------------------------------------------------------------------
// 1. UI Markup & Structure Tests in tenant_portal.php
// -----------------------------------------------------------------------------
$tenantHtml = file_get_contents(__DIR__ . '/../tenant_portal.php');

testAssert(
    "Verification modal is hidden by default (display: none)",
    str_contains($tenantHtml, 'id="verifyAccountModal"') && str_contains($tenantHtml, 'style="display: none;"')
);

testAssert(
    "Multi-step progress indicator exists (Step 1 Upload ID, Step 2 Face Recognition)",
    str_contains($tenantHtml, 'id="stepPill1"') && str_contains($tenantHtml, 'id="stepPill2"') &&
    str_contains($tenantHtml, 'Upload ID') && str_contains($tenantHtml, 'Face Recognition')
);

testAssert(
    "Step 1 View contains ID dropdown and drop zone with Next button",
    str_contains($tenantHtml, 'id="verifyStep1View"') &&
    str_contains($tenantHtml, 'id="idTypeSelect"') &&
    str_contains($tenantHtml, 'id="idDropZone"') &&
    str_contains($tenantHtml, 'id="btnNextStep1"') &&
    str_contains($tenantHtml, 'goToVerificationStep(2)')
);

testAssert(
    "Step 2 View contains Live Webcam Video, guide overlay, and canvas",
    str_contains($tenantHtml, 'id="verifyStep2View"') &&
    str_contains($tenantHtml, 'id="webcamVideo"') &&
    str_contains($tenantHtml, 'id="faceCaptureCanvas"') &&
    str_contains($tenantHtml, 'id="cameraGuideOverlay"')
);

testAssert(
    "Step 2 View contains Retake, Back, Final Submit buttons, and Auto-Capture Logic",
    str_contains($tenantHtml, 'id="btnRetakeFace"') &&
    str_contains($tenantHtml, 'id="btnBackToStep1"') &&
    str_contains($tenantHtml, 'id="btnSubmitVerification"') &&
    str_contains($tenantHtml, 'captureLiveFace()')
);

testAssert(
    "Strict Modal Trigger: Account Information section has btnVerifyAccount triggering openVerifyAccountModal()",
    str_contains($tenantHtml, 'id="btnVerifyAccount"') &&
    str_contains($tenantHtml, 'onclick="openVerifyAccountModal()"')
);

testAssert(
    "Account Information displays Date of Birth",
    str_contains($tenantHtml, 'Date of Birth:')
);

testAssert(
    "Visual ID Image Preview container exists in tenant_portal.php",
    str_contains($tenantHtml, 'id="idImagePreviewCard"') &&
    str_contains($tenantHtml, 'id="idVisualPreviewImg"')
);

testAssert(
    "Vanilla JS FileReader image preview logic exists in tenant_portal.php",
    str_contains($tenantHtml, 'new FileReader()') &&
    str_contains($tenantHtml, 'readAsDataURL')
);

testAssert(
    "Client-side Canvas Image Pre-processing function exists in tenant_portal.php",
    str_contains($tenantHtml, 'function preprocessIdImageForOCR') &&
    str_contains($tenantHtml, 'getImageData') &&
    str_contains($tenantHtml, 'putImageData')
);

// -----------------------------------------------------------------------------
// 2. Hardcoded Face++ Credentials & Config Checks
// -----------------------------------------------------------------------------
testAssert("FACEPP_API_KEY is hardcoded to specified key", FACEPP_API_KEY === 'b0uSL9nuSnJO0TXD6758FDzcIvM5Eipb');
testAssert("FACEPP_API_SECRET is hardcoded to specified secret", FACEPP_API_SECRET === 'dNrr8WS5LeYeclYEEFZgeFQp67osUh29');
testAssert("FACEPP_COMPARE_URL is correct Face++ v3 compare endpoint", FACEPP_COMPARE_URL === 'https://api-us.faceplusplus.com/facepp/v3/compare');

// -----------------------------------------------------------------------------
// 3. Backend Verification Logic & End-to-End Database Scenarios
// -----------------------------------------------------------------------------
$pdo = Database::getConnection();

// Create a dedicated test tenant for verification tests
$pdo->exec("DELETE FROM users WHERE username = 'test_verify_tenant'");
$testPassHash = password_hash('123456', PASSWORD_BCRYPT);
$pdo->exec("
    INSERT INTO users (unit_id, username, name, email, password_hash, role, verification_status, birthday, is_active, created_at)
    VALUES (1, 'test_verify_tenant', 'Juan Dela Cruz', 'juan_test@example.com', '$testPassHash', 'tenant', 'unverified', '1995-04-12', 1, NOW())
");
$testTenantId = (int)$pdo->lastInsertId();
testAssert("Created clean test tenant (id: $testTenantId)", $testTenantId > 0);

$lenaPath = __DIR__ . '/lena.jpg';
$messiPath = __DIR__ . '/messi.jpg';

// SCENARIO A: Both Name and Birthday Match Completely + Face++ Match -> VERIFIED
$mockOcrA = [
    'full_name' => 'Juan Dela Cruz',
    'birthday' => '1995-04-12',
    'id_type' => "Driver's License"
];
$fetchTenantStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$fetchTenantStmt->execute([':id' => $testTenantId]);
$tenantRow = $fetchTenantStmt->fetch();

$textResultA = VerificationService::compareIdTextWithAccount($mockOcrA, $tenantRow);
$faceResultA = VerificationService::compareFacesWithFacePlusPlus($lenaPath, $lenaPath);

$bothPassedA = $textResultA['match'] && $faceResultA['is_match'];
testAssert("Scenario A: Both text and face match satisfied", $bothPassedA);

if ($bothPassedA) {
    $statusA = 'verified';
    $notesA = "Verified: OCR matched {$textResultA['extracted_name']}, Face++ confidence {$faceResultA['confidence']}%";
    $upStmt = $pdo->prepare("UPDATE users SET verification_status = :s, verification_notes = :n, id_verified_at = NOW() WHERE id = :id");
    $upStmt->execute([':s' => $statusA, ':n' => $notesA, ':id' => $testTenantId]);
}

$fetchTenantStmt->execute([':id' => $testTenantId]);
$checkA = $fetchTenantStmt->fetch();
testAssert("Scenario A: User verification_status updated to 'verified'", $checkA['verification_status'] === 'verified');
testAssert("Scenario A: id_verified_at is set", !empty($checkA['id_verified_at']));

// SCENARIO B: Strict Rule: Birthday is Mismatched -> STRICTLY REJECTED!
$mockOcrB = [
    'full_name' => 'Juan Dela Cruz',
    'birthday' => '1990-01-01', // Mismatched birthday
    'id_type' => "Driver's License"
];
$textResultB = VerificationService::compareIdTextWithAccount($mockOcrB, $tenantRow);
testAssert("Scenario B (Strict): Birthday mismatch strictly rejected", $textResultB['match'] === false && $textResultB['birthday_matched'] === false);

// SCENARIO C: Strict Rule: Birthday is Missing on ID -> STRICTLY REJECTED!
$mockOcrC = [
    'full_name' => 'Juan Dela Cruz',
    'birthday' => '', // Missing birthday
    'id_type' => "Student ID"
];
$textResultC = VerificationService::compareIdTextWithAccount($mockOcrC, $tenantRow);
testAssert("Scenario C (Strict): Missing birthday strictly rejected", $textResultC['match'] === false && $textResultC['birthday_matched'] === false);

// SCENARIO D: BOTH Name and Birthday Mismatch -> REJECTED!
$mockOcrD = [
    'full_name' => 'Pedro Penduko',
    'birthday' => '1980-05-20',
    'id_type' => "Driver's License"
];
$textResultD = VerificationService::compareIdTextWithAccount($mockOcrD, $tenantRow);
testAssert("Scenario D: Both name and birthday mismatch detected and halted", !$textResultD['match']);
testAssert("Scenario D: Strict identity failure reason provided", str_contains($textResultD['reason'], 'Verification Failed: Account information does not match the ID.'));

// SCENARIO E: Face Mismatch (Different Face, e.g. Lena vs Messi) -> REJECTED Even if Name Matches
sleep(2);
$mockOcrE = [
    'full_name' => 'Juan Dela Cruz',
    'birthday' => '1995-04-12',
    'id_type' => "Driver's License"
];
$textResultE = VerificationService::compareIdTextWithAccount($mockOcrE, $tenantRow);
$faceResultE = VerificationService::compareFacesWithFacePlusPlus($lenaPath, $messiPath);

$bothPassedE = $textResultE['match'] && $faceResultE['is_match'];
testAssert("Scenario E: Face biometric mismatch fails overall verification", !$bothPassedE);

// Clean up test tenant
$pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $testTenantId]);
testAssert("Cleaned up test tenant", true);

echo "\nResult: " . ($allPassed ? "ALL VERIFICATION FLOW TESTS PASSED!" : "FAILURES DETECTED!") . "\n";
if (!$allPassed) exit(1);
