<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/credentials.php';
require_once __DIR__ . '/../includes/verification_service.php';

echo "=== TESTING AUTOMATED FACE ID LOGIN FEATURE (UI & 1:N MATCHING) ===\n\n";

$pass = 0;
$fail = 0;

function assertCondition(string $name, bool $cond, string $details = '') {
    global $pass, $fail;
    if ($cond) {
        echo " [PASS] $name\n";
        $pass++;
    } else {
        echo " [FAIL] $name - $details\n";
        $fail++;
    }
}

// -----------------------------------------------------------------------------
// 1. UI & MARKUP TESTS IN login.php
// -----------------------------------------------------------------------------
$loginHtml = file_get_contents(__DIR__ . '/../login.php');

assertCondition(
    "Prominent 'Use Face ID' button exists below standard form in login.php",
    str_contains($loginHtml, 'id="btnUseFaceId"') &&
    str_contains($loginHtml, 'switchToFaceIdLogin()') &&
    str_contains($loginHtml, 'Use Face ID')
);

assertCondition(
    "Mirrored live webcam video feed exists with CSS scaleX(-1)",
    str_contains($loginHtml, 'id="faceWebcamVideo"') &&
    str_contains($loginHtml, 'transform: scaleX(-1)')
);

assertCondition(
    "'Cancel / Use Password' toggle button exists",
    str_contains($loginHtml, 'id="btnCancelFaceId"') &&
    str_contains($loginHtml, 'switchToPasswordLogin()') &&
    str_contains($loginHtml, 'Cancel / Use Password')
);

assertCondition(
    "No manual capture button exists inside Face ID section (auto-capture only)",
    !str_contains($loginHtml, 'id="btnCaptureFace"') &&
    !str_contains($loginHtml, 'Capture Face')
);

assertCondition(
    "Dynamic visual feedback elements exist ('Scanning face...', 'Hold still...', 'Verifying...')",
    str_contains($loginHtml, 'id="faceStatusPill"') &&
    str_contains($loginHtml, 'id="faceStatusText"') &&
    str_contains($loginHtml, 'Scanning face...') &&
    str_contains($loginHtml, 'Hold still...') &&
    str_contains($loginHtml, 'Verifying...')
);

assertCondition(
    "2-second hold-still progress track exists in Vanilla JS logic",
    str_contains($loginHtml, 'HOLD_DURATION_MS = 2000') &&
    str_contains($loginHtml, 'id="faceHoldProgressFill"')
);

assertCondition(
    "Timed notification alert bar exists with required error message",
    str_contains($loginHtml, 'id="faceLoginAlert"') &&
    str_contains($loginHtml, 'Face not recognized. Please try again or use your password.')
);

// -----------------------------------------------------------------------------
// 2. BACKEND API TESTS: login_face_verify.php via HTTP cURL
// -----------------------------------------------------------------------------
$baseUrl = 'http://localhost/RentConnect';
$cookieJar = tempnam(sys_get_temp_dir(), 'face_login_cookie_');

// 2.1 Rejects GET requests
$ch = curl_init("$baseUrl/login_face_verify.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar
]);
$resGet = curl_exec($ch);
$codeGet = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertCondition("login_face_verify.php rejects GET requests with HTTP 405", $codeGet === 405);

// 2.2 Rejects empty payload
$ch = curl_init("$baseUrl/login_face_verify.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_POSTFIELDS => []
]);
$resEmpty = curl_exec($ch);
$codeEmpty = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertCondition("login_face_verify.php rejects empty POST with HTTP 422", $codeEmpty === 422);

// 2.3 Rejects corrupted image data
$ch = curl_init("$baseUrl/login_face_verify.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_POSTFIELDS => ['image_data' => 'not_base64_too_short']
]);
$resCorrupt = curl_exec($ch);
$codeCorrupt = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertCondition("login_face_verify.php rejects invalid image data with HTTP 422", $codeCorrupt === 422);

// Generate dummy base64 JPEG using lena.jpg
$dummyJpeg = file_get_contents(__DIR__ . '/lena.jpg');
$dummyBase64 = 'data:image/jpeg;base64,' . base64_encode($dummyJpeg);

// 2.4 Mock Face Mismatch
$ch = curl_init("$baseUrl/login_face_verify.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_POSTFIELDS => json_encode(['image_data' => $dummyBase64]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Mock-Face-User: none'
    ]
]);
$resNoMatch = curl_exec($ch);
$codeNoMatch = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$dataNoMatch = json_decode((string)$resNoMatch, true);

assertCondition(
    "Unrecognized face returns HTTP 401 with 'Face not recognized. Please try again or use your password.'",
    $codeNoMatch === 401 && ($dataNoMatch['message'] ?? '') === 'Face not recognized. Please try again or use your password.'
);

// 2.5 Mock Face Match - Tenant Role (RC001 / Lara Janelle Lucero)
$ch = curl_init("$baseUrl/login_face_verify.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_POSTFIELDS => json_encode(['image_data' => $dummyBase64]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Mock-Face-User: RC001'
    ]
]);
$resTenant = curl_exec($ch);
$codeTenant = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$dataTenant = json_decode((string)$resTenant, true);

assertCondition(
    "Tenant Face ID match initializes session and returns redirect_url: tenant_portal.php",
    $codeTenant === 200 &&
    ($dataTenant['status'] ?? '') === 'success' &&
    ($dataTenant['role'] ?? '') === 'tenant' &&
    ($dataTenant['redirect_url'] ?? '') === 'tenant_portal.php' &&
    ($dataTenant['user']['username'] ?? '') === 'RC001'
);

// Verify Tenant session access via authenticated page check
$ch = curl_init("$baseUrl/tenant_portal.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieJar
]);
$resTenantPage = curl_exec($ch);
$codeTenantPage = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertCondition(
    "Tenant session is authenticated and can access tenant_portal.php directly",
    $codeTenantPage === 200 && str_contains((string)$resTenantPage, 'RC001')
);

// 2.6 Mock Face Match - Admin Role
$cookieJarAdmin = tempnam(sys_get_temp_dir(), 'face_admin_cookie_');
$ch = curl_init("$baseUrl/login_face_verify.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJarAdmin,
    CURLOPT_POSTFIELDS => json_encode(['image_data' => $dummyBase64]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Mock-Face-User: admin'
    ]
]);
$resAdmin = curl_exec($ch);
$codeAdmin = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$dataAdmin = json_decode((string)$resAdmin, true);

assertCondition(
    "Admin Face ID match initializes session and returns redirect_url: admin_dashboard.php",
    $codeAdmin === 200 &&
    ($dataAdmin['status'] ?? '') === 'success' &&
    in_array($dataAdmin['role'] ?? '', ['landlord', 'admin']) &&
    ($dataAdmin['redirect_url'] ?? '') === 'admin_dashboard.php' &&
    ($dataAdmin['user']['username'] ?? '') === 'admin'
);

// -----------------------------------------------------------------------------
// 3. LIVE 1:N FACE++ BIOMETRIC COMPARISON TEST
// -----------------------------------------------------------------------------
// Ensure test user RC001 has verified photo configured
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$user21PhotoName = 'face_user21_20261021_152757_f591bd7a.jpg';
$user21PhotoPath = __DIR__ . '/../uploads/id_verifications/' . $user21PhotoName;
$pdo->prepare("UPDATE users SET live_photo_filename = :p, verification_status = 'verified' WHERE username = 'RC001'")
    ->execute([':p' => $user21PhotoName]);

if (file_exists($user21PhotoPath)) {
    echo "\nTesting Live 1:N Biometric Matching with User 21 (Lara Janelle Lucero)...\n";
    $liveFaceBytes = file_get_contents($user21PhotoPath);
    $liveFaceBase64 = 'data:image/jpeg;base64,' . base64_encode($liveFaceBytes);

    $cookieJarLive = tempnam(sys_get_temp_dir(), 'face_live_cookie_');
    $ch = curl_init("$baseUrl/login_face_verify.php");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJarLive,
        CURLOPT_POSTFIELDS => json_encode(['image_data' => $liveFaceBase64]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ]
    ]);
    $resLiveMatch = curl_exec($ch);
    $codeLiveMatch = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $dataLiveMatch = json_decode((string)$resLiveMatch, true);

    echo "Live Match Response (HTTP $codeLiveMatch): " . substr((string)$resLiveMatch, 0, 150) . "...\n";
    assertCondition(
        "Live 1:N Face++ matching automatically identifies verified user by face",
        $codeLiveMatch === 200 &&
        ($dataLiveMatch['status'] ?? '') === 'success' &&
        ($dataLiveMatch['user']['username'] ?? '') === 'RC001' &&
        ($dataLiveMatch['redirect_url'] ?? '') === 'tenant_portal.php'
    );
} else {
    echo "Notice: Stored photo for user 21 not found, skipping Live Face++ comparison.\n";
}

@unlink($cookieJar);
@unlink($cookieJarAdmin);
if (isset($cookieJarLive)) @unlink($cookieJarLive);

echo "\nSummary: Passed: $pass, Failed: $fail\n";
