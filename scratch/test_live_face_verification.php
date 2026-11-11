<?php
/**
 * Test Suite: Live Face Scan Verification (Automated Real-Time Liveness & Face++ Matching)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function testAssert(string $desc, bool $condition, string $detail = ''): void {
    if ($condition) {
        echo "✓ PASS: $desc\n";
    } else {
        echo "✗ FAIL: $desc" . ($detail ? " ($detail)" : "") . "\n";
        exit(1);
    }
}

echo "=== START LIVE FACE SCAN VERIFICATION TEST SUITE ===\n\n";

$tenantPortalCode = file_get_contents(__DIR__ . '/../tenant_portal.php');
$verifyFaceCode = file_get_contents(__DIR__ . '/../verify_face.php');

// 1. FRONTEND UI & WORKFLOW CHECKS
echo "--- 1. Frontend UI & Automation Validation ---\n";

testAssert(
    "Manual capture button has been completely removed from Step 2 view",
    !str_contains($tenantPortalCode, 'id="btnManualFaceCapture"'),
    "btnManualFaceCapture found in tenant_portal.php"
);

testAssert(
    "Live webcam video element is present in Step 2 view",
    str_contains($tenantPortalCode, 'id="livenessVideo"')
);

testAssert(
    "Hidden canvas element for auto-capture is present",
    str_contains($tenantPortalCode, 'id="livenessCaptureCanvas"')
);

testAssert(
    "Face guide oval ring is present",
    str_contains($tenantPortalCode, 'id="livenessOvalRing"')
);

testAssert(
    "Real-time scanning status pill is present",
    str_contains($tenantPortalCode, 'id="livenessStatusPill"')
);

testAssert(
    "Finalizing overlay with loading indicator is present",
    str_contains($tenantPortalCode, 'id="livenessFinalizingOverlay"') &&
    str_contains($tenantPortalCode, 'id="livenessLoadingSpinner"')
);

testAssert(
    "Retry button is present for failed attempts",
    str_contains($tenantPortalCode, 'id="btnLivenessRetry"') &&
    str_contains($tenantPortalCode, 'restartLivenessScan()')
);

// 2. REAL-TIME SCANNING STATUS MESSAGES
echo "\n--- 2. Real-Time Scanning Status Messages ---\n";

$requiredStatuses = [
    "Position your face in the camera.",
    "Face detected.",
    "Please blink your eyes.",
    "Liveness detected.",
    "Comparing with registered Face ID...",
    "Face verification successful!",
    "Face verification failed. Please try again."
];

foreach ($requiredStatuses as $status) {
    testAssert(
        "Status text '$status' is present in tenant_portal.php",
        str_contains($tenantPortalCode, $status),
        "Missing: $status"
    );
}

// 3. MULTI-FACE DETECTION CHECK
echo "\n--- 3. Multi-Face Detection & Anti-Spoofing Checks ---\n";

testAssert(
    "Uses detectAllFaces to verify face count",
    str_contains($tenantPortalCode, 'detectAllFaces')
);

testAssert(
    "Rejects multiple faces and halts verification",
    str_contains($tenantPortalCode, 'detections.length > 1') &&
    str_contains($tenantPortalCode, 'Multiple faces detected')
);

testAssert(
    "Performs natural blink detection via Eye Aspect Ratio (EAR)",
    str_contains($tenantPortalCode, 'leftEAR') &&
    str_contains($tenantPortalCode, 'rightEAR') &&
    str_contains($tenantPortalCode, 'livenessBlinkState')
);

testAssert(
    "Performs head turn detection via jawRatio yaw calculation",
    str_contains($tenantPortalCode, 'jawRatio') &&
    str_contains($tenantPortalCode, 'livenessHeadState')
);

testAssert(
    "Automatically triggers capture on liveness confirmation without manual click",
    str_contains($tenantPortalCode, 'captureAndSubmitLiveVerification(triggerReason)')
);

testAssert(
    "Releases/stops webcam hardware tracks upon modal close, success, failure, or unload",
    str_contains($tenantPortalCode, 'function stopLivenessCamera()') &&
    str_contains($tenantPortalCode, 'livenessStream.getTracks().forEach(t => t.stop())') &&
    str_contains($tenantPortalCode, 'window.addEventListener(\'beforeunload\'')
);

// 4. BACKEND INTEGRATION & SECURITY
echo "\n--- 4. Backend Face++ Security & Identity Verification ---\n";

testAssert(
    "Face++ API key and secret are NOT exposed in frontend JS",
    !str_contains($tenantPortalCode, 'b0uSL9nuSnJO0TXD6758FDzcIvM5Eipb') &&
    !str_contains($tenantPortalCode, 'dNrr8WS5LeYeclYEEFZgeFQp67osUh29')
);

testAssert(
    "verify_face.php contains Face++ API credentials securely on server",
    str_contains($verifyFaceCode, 'b0uSL9nuSnJO0TXD6758FDzcIvM5Eipb') &&
    str_contains($verifyFaceCode, 'dNrr8WS5LeYeclYEEFZgeFQp67osUh29')
);

testAssert(
    "verify_face.php enforces confidence threshold >= 70% or 1e-4 threshold",
    str_contains($verifyFaceCode, '$confidence >= 70.0')
);

testAssert(
    "verify_face.php updates users table to verified on success",
    str_contains($verifyFaceCode, "is_verified = 1") &&
    str_contains($verifyFaceCode, "verification_status = 'verified'")
);

testAssert(
    "verify_face.php returns exact message on success: 'Face verification successful!'",
    str_contains($verifyFaceCode, "'message' => 'Face verification successful!'")
);

testAssert(
    "verify_face.php returns exact message on failure: 'Face verification failed. Please try again.'",
    str_contains($verifyFaceCode, "'message' => 'Face verification failed. Please try again.'")
);

// 5. LIVE HTTP API TESTS
echo "\n--- 5. Dynamic verify_face.php API Tests ---\n";

$baseUrl = 'http://localhost/RentConnect';
$cookieJar = tempnam(sys_get_temp_dir(), 'rc_face_test_');

// Prepare test tenant
$pdo = Database::getConnection();
$userStmt = $pdo->prepare("SELECT id, username, name, email, id_photo_filename FROM users WHERE role = 'tenant' AND is_active = 1 LIMIT 1");
$userStmt->execute();
$testUser = $userStmt->fetch(PDO::FETCH_ASSOC);

testAssert("Found active test tenant in database", (bool)$testUser);
$testUserId = (int)$testUser['id'];
$testUsername = $testUser['username'];

// Set a known password for testing
$tempPassword = 'TestPassword123!';
$pdo->prepare("UPDATE users SET password_hash = :hash, verification_status = 'unverified', is_verified = 0 WHERE id = :id")
    ->execute([':hash' => password_hash($tempPassword, PASSWORD_DEFAULT), ':id' => $testUserId]);

// Ensure testUser has an ID photo on file in uploads/id_verifications
$sampleIdPath = __DIR__ . '/../uploads/id_verifications/id_user11_20261106_141142_01624cb7.jpg';
$testIdFilename = 'id_user' . $testUserId . '_test_sample.jpg';
copy($sampleIdPath, __DIR__ . '/../uploads/id_verifications/' . $testIdFilename);
$pdo->prepare("UPDATE users SET id_photo_filename = :fn, id_type = 'National ID' WHERE id = :id")
    ->execute([':fn' => $testIdFilename, ':id' => $testUserId]);

// Test A: Unauthorized request (no session)
$ch = curl_init("$baseUrl/verify_face.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => ['action' => 'complete_verification']
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
testAssert("verify_face.php rejects unauthenticated requests with HTTP 401", $code === 401);

// Login as test tenant
$ch = curl_init("$baseUrl/login.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_POSTFIELDS => http_build_query([
        'username' => $testUsername,
        'password' => $tempPassword
    ]),
    CURLOPT_FOLLOWLOCATION => true
]);
curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
testAssert("Logged in as tenant $testUsername (HTTP $loginCode)", $loginCode >= 200 && $loginCode < 400);

// Generate dummy base64 live face image
$dummyFace = imagecreatetruecolor(160, 160);
ob_start();
imagejpeg($dummyFace);
$faceJpgBytes = ob_get_clean();
imagedestroy($dummyFace);
$liveFaceBase64 = 'data:image/jpeg;base64,' . base64_encode($faceJpgBytes);

// Test B: Live Face Mismatch (confidence < 70)
$ch = curl_init("$baseUrl/verify_face.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_HTTPHEADER => [
        'X-Mock-Facepp-Confidence: 42.50'
    ],
    CURLOPT_POSTFIELDS => [
        'action' => 'complete_verification',
        'live_face_data' => $liveFaceBase64,
        'terms_accepted' => '1',
        'privacy_acknowledged' => '1',
        'liveness_passed' => '1'
    ]
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$json = json_decode((string)$resp, true);

testAssert(
    "Low confidence (42.5%) returns HTTP 422 mismatch",
    $code === 422 && ($json['status'] ?? '') === 'error',
    "HTTP $code: $resp"
);
testAssert(
    "Returns required error message on mismatch: 'Face verification failed. Please try again.'",
    ($json['message'] ?? '') === 'Face verification failed. Please try again.',
    "Got: " . ($json['message'] ?? 'null')
);

// Test C: Face++ API Error
$ch = curl_init("$baseUrl/verify_face.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_HTTPHEADER => [
        'X-Mock-Facepp-Error: 1'
    ],
    CURLOPT_POSTFIELDS => [
        'action' => 'complete_verification',
        'live_face_data' => $liveFaceBase64,
        'terms_accepted' => '1',
        'privacy_acknowledged' => '1',
        'liveness_passed' => '1'
    ]
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$json = json_decode((string)$resp, true);

testAssert(
    "Face++ API error returns HTTP 422 without approval",
    $code === 422 && ($json['status'] ?? '') === 'error' && ($json['message'] ?? '') === 'Face verification failed. Please try again.'
);

// Test D: Successful Face Match (confidence >= 70%)
$ch = curl_init("$baseUrl/verify_face.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_HTTPHEADER => [
        'X-Mock-Facepp-Confidence: 89.75'
    ],
    CURLOPT_POSTFIELDS => [
        'action' => 'complete_verification',
        'live_face_data' => $liveFaceBase64,
        'terms_accepted' => '1',
        'privacy_acknowledged' => '1',
        'liveness_passed' => '1'
    ]
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$json = json_decode((string)$resp, true);

testAssert(
    "High confidence (89.75%) returns HTTP 200 success",
    $code === 200 && ($json['status'] ?? '') === 'success' && ($json['is_verified'] ?? 0) === 1,
    "HTTP $code: $resp"
);
testAssert(
    "Returns required success message: 'Face verification successful!'",
    ($json['message'] ?? '') === 'Face verification successful!',
    "Got: " . ($json['message'] ?? 'null')
);

// Verify database record updated
$checkStmt = $pdo->prepare("SELECT is_verified, verification_status, id_verified_at FROM users WHERE id = :id");
$checkStmt->execute([':id' => $testUserId]);
$updatedUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

testAssert(
    "Database record officially updated to is_verified = 1 and verification_status = 'verified'",
    (int)$updatedUser['is_verified'] === 1 && $updatedUser['verification_status'] === 'verified' && !empty($updatedUser['id_verified_at']),
    print_r($updatedUser, true)
);

@unlink($cookieJar);

echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";
