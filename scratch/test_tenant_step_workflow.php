<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

echo "========================================================================\n";
echo "TESTING TENANT PHASE 2 QUALITY CHECK & PHASE 3 ANTI-SPOOFING WORKFLOW\n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition(string $title, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$title}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failCount++;
    }
}

// =============================================================================
// TEST SUITE 1: UI MARKUP & STEP 2 UNMASKED FEED
// =============================================================================
echo "--- TEST SUITE 1: UI Markup & Step 2 Unmasked Feed ---\n";
$portalHtml = file_get_contents(__DIR__ . '/../tenant_portal.php');

// Extract Step 2 markup
$step2Start = strpos($portalHtml, 'id="verifyStep2View"');
$step2End = strpos($portalHtml, 'id="verifyStep3View"', $step2Start);
$step2Markup = substr($portalHtml, $step2Start, $step2End - $step2Start);

assertCondition(
    "Step 2 completely removed GCash-style oval cutout overlay (cameraGuideOverlay & face-oval-guide removed)",
    !str_contains($step2Markup, 'id="cameraGuideOverlay"') &&
    !str_contains($step2Markup, 'face-oval-guide') &&
    !str_contains($step2Markup, 'face-oval-ring')
);

assertCondition(
    "Step 2 webcam feed displays normally without mask overlay",
    str_contains($step2Markup, 'id="webcamVideo"') &&
    str_contains($step2Markup, 'Unmasked full feed')
);

assertCondition(
    "Step 2 contains accessible Back to ID button (btnBackToStep1 calling goToVerificationStep(1))",
    str_contains($step2Markup, 'id="btnBackToStep1"') &&
    str_contains($step2Markup, 'onclick="goToVerificationStep(1)"')
);

assertCondition(
    "Step 2 contains Next: Live Testing button disabled until photo is captured & validated",
    str_contains($step2Markup, 'id="btnNextStep2"') &&
    str_contains($step2Markup, 'onclick="goToVerificationStep(3)"') &&
    str_contains($step2Markup, 'disabled')
);

// Step 3 View check
$step3ViewStart = strpos($portalHtml, 'id="verifyStep3View"');
$step3ViewEnd = strpos($portalHtml, '</form>', $step3ViewStart);
$step3Content = substr($portalHtml, $step3ViewStart, $step3ViewEnd - $step3ViewStart);

assertCondition(
    "Step 3 completely removed manual confirmation buttons (btnManualLivenessTrigger, etc.)",
    !str_contains($step3Content, 'btnManualLivenessTrigger') &&
    !str_contains($step3Content, 'I have completed') &&
    !str_contains($step3Content, 'type="submit"')
);

assertCondition(
    "Step 3 contains accessible Back button to Step 2",
    str_contains($step3Content, 'id="btnBackToStep2"') &&
    str_contains($step3Content, 'onclick="goToVerificationStep(2)"')
);

// =============================================================================
// TEST SUITE 2: PHASE 2 QUALITY CHECK & PHASE 3 ANTI-SPOOFING LOGIC
// =============================================================================
echo "\n--- TEST SUITE 2: JavaScript Quality Check & Anti-Spoofing ---\n";

assertCondition(
    "takeStaticFaceSnapshot captures photo seamlessly without frontend quality or presence validation",
    str_contains($portalHtml, 'function takeStaticFaceSnapshot()') &&
    str_contains($portalHtml, 'capturedFaceDataUrl = canvas.toDataURL') &&
    str_contains($portalHtml, 'btnNext.disabled = false') &&
    !str_contains($portalHtml, 'faceapi.detectSingleFace(canvas')
);

assertCondition(
    "Phase 2 does NOT block on frontend for blurry image or missing face",
    !str_contains($portalHtml, 'Quality Check Failed') &&
    !str_contains($portalHtml, 'score >= 0.55')
);

assertCondition(
    "goToVerificationStep(3) allows seamless transition as soon as photo is captured",
    str_contains($portalHtml, 'if (!capturedFaceDataUrl) {') &&
    !str_contains($portalHtml, 'if (!capturedFaceDataUrl || !isFaceCaptureValid)')
);

assertCondition(
    "submitFaceCompare seamlessly advances to Step 3 when photo is captured",
    str_contains($portalHtml, 'function submitFaceCompare()') &&
    str_contains($portalHtml, 'goToVerificationStep(3)')
);

assertCondition(
    "Phase 3 defines dual mathematical state machines for Blink and Head Turn",
    str_contains($portalHtml, 'livenessBlinkState') &&
    str_contains($portalHtml, 'OPEN_OBSERVED') &&
    str_contains($portalHtml, 'DROPPED_CLOSED') &&
    str_contains($portalHtml, 'livenessHeadState') &&
    str_contains($portalHtml, 'CENTER_OBSERVED') &&
    str_contains($portalHtml, 'TURNED_AWAY')
);

// =============================================================================
// TEST SUITE 3: MATHEMATICAL SIMULATION OF ANTI-SPOOFING STATE MACHINE
// =============================================================================
echo "\n--- TEST SUITE 3: Mathematical State Machine Simulation ---\n";

// Helper function implementing the exact state machine from tenant_portal.php
function simulateLivenessStep(
    float $avgEAR, 
    float $jawRatio, 
    string &$blinkState, 
    string &$headState, 
    int &$openFrames, 
    int &$centerFrames
): bool {
    // Blink state machine
    if ($blinkState === 'INITIAL') {
        if ($avgEAR >= 0.26) {
            $openFrames++;
            if ($openFrames >= 2) {
                $blinkState = 'OPEN_OBSERVED';
            }
        } else {
            $openFrames = 0;
        }
    } elseif ($blinkState === 'OPEN_OBSERVED') {
        if ($avgEAR < 0.22) {
            $blinkState = 'DROPPED_CLOSED';
        }
    } elseif ($blinkState === 'DROPPED_CLOSED') {
        if ($avgEAR >= 0.25) {
            $blinkState = 'COMPLETED';
        }
    }

    // Head turn state machine
    if ($headState === 'INITIAL') {
        if ($jawRatio >= 0.75 && $jawRatio <= 1.30) {
            $centerFrames++;
            if ($centerFrames >= 2) {
                $headState = 'CENTER_OBSERVED';
            }
        } else {
            $centerFrames = 0;
        }
    } elseif ($headState === 'CENTER_OBSERVED') {
        if ($jawRatio < 0.52 || $jawRatio > 1.85) {
            $headState = 'TURNED_AWAY';
        }
    } elseif ($headState === 'TURNED_AWAY') {
        if ($jawRatio >= 0.70 && $jawRatio <= 1.40) {
            $headState = 'COMPLETED';
        }
    }

    return ($blinkState === 'COMPLETED' || $headState === 'COMPLETED');
}

// 3.1 Static Photo Test: Photo held to camera (constant EAR = 0.29, constant jawRatio = 1.05)
$bState = 'INITIAL'; $hState = 'INITIAL'; $oFrames = 0; $cFrames = 0;
$staticTriggered = false;
for ($frame = 1; $frame <= 50; $frame++) {
    if (simulateLivenessStep(0.29, 1.05, $bState, $hState, $oFrames, $cFrames)) {
        $staticTriggered = true;
        break;
    }
}
assertCondition(
    "Anti-spoofing test: Static photo on phone/paper (constant EAR & Yaw) NEVER triggers verification across 50 frames",
    !$staticTriggered && $bState === 'OPEN_OBSERVED' && $hState === 'CENTER_OBSERVED'
);

// 3.2 Real Blink Test: Open baseline -> Eyes Closed -> Eyes Re-opened
$bState = 'INITIAL'; $hState = 'INITIAL'; $oFrames = 0; $cFrames = 0;
$blinkFrames = [
    [0.28, 1.0], // frame 1: open
    [0.28, 1.0], // frame 2: open -> transitions to OPEN_OBSERVED
    [0.18, 1.0], // frame 3: eyes dropped closed -> transitions to DROPPED_CLOSED
    [0.27, 1.0], // frame 4: eyes re-opened -> transitions to COMPLETED
];
$blinkPassed = false;
foreach ($blinkFrames as [$ear, $yaw]) {
    if (simulateLivenessStep($ear, $yaw, $bState, $hState, $oFrames, $cFrames)) {
        $blinkPassed = true;
        break;
    }
}
assertCondition(
    "Real Blink test: Full cycle (open -> closed -> re-opened) successfully completes verification",
    $blinkPassed && $bState === 'COMPLETED'
);

// 3.3 Real Head Turn Test: Center baseline -> Turn Left -> Return to Center
$bState = 'INITIAL'; $hState = 'INITIAL'; $oFrames = 0; $cFrames = 0;
$turnFrames = [
    [0.28, 1.0],  // frame 1: centered
    [0.28, 1.0],  // frame 2: centered -> transitions to CENTER_OBSERVED
    [0.28, 0.40], // frame 3: turned left -> transitions to TURNED_AWAY
    [0.28, 0.98], // frame 4: returned to center -> transitions to COMPLETED
];
$turnPassed = false;
foreach ($turnFrames as [$ear, $yaw]) {
    if (simulateLivenessStep($ear, $yaw, $bState, $hState, $oFrames, $cFrames)) {
        $turnPassed = true;
        break;
    }
}
assertCondition(
    "Real Head Turn test: Full cycle (centered -> turned -> returned to center) successfully completes verification",
    $turnPassed && $hState === 'COMPLETED'
);

// =============================================================================
// TEST SUITE 4: BACKEND VERIFICATION ENDPOINT (verify_face.php)
// =============================================================================
echo "\n--- TEST SUITE 4: Backend Strict Verification & DB Updates ---\n";

$pdo = Database::getConnection();

// Prepare a test tenant
$stmt = $pdo->query("SELECT * FROM users WHERE role = 'tenant' LIMIT 1");
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    $pdo->exec("INSERT INTO users (username, name, email, password_hash, role, verification_status, is_verified) 
                VALUES ('unit_tenant_step', 'Unit Tenant Step', 'unit_step@example.com', 'hash', 'tenant', 'unverified', 0)");
    $tenantId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
}

$tenantId = (int)$tenant['id'];

// Reset verification status
$pdo->prepare("UPDATE users SET is_verified = 0, verification_status = 'unverified' WHERE id = :id")->execute([':id' => $tenantId]);

// Test 4.1: Blurry/no face detected on captured snapshot (empty faces2)
$emptyFacesOutput = shell_exec('php ' . escapeshellarg(__DIR__ . '/run_subtest.php') . ' empty_faces2');
$emptyFacesJson = json_decode((string)$emptyFacesOutput, true);

assertCondition(
    "Backend empty faces2 returns 422 with message 'Face not detected or image is too blurry. Please retake the photo.'",
    isset($emptyFacesJson['status']) && $emptyFacesJson['status'] === 'error' &&
    str_contains($emptyFacesJson['message'] ?? '', 'Face not detected or image is too blurry')
);

// Test 4.2: Face++ mismatch (< 70 confidence and below 1e-4 threshold)
$lowOutput = shell_exec('php ' . escapeshellarg(__DIR__ . '/run_subtest.php') . ' low');
$lowJson = json_decode((string)$lowOutput, true);

$checkUser = $pdo->prepare("SELECT is_verified, verification_status FROM users WHERE id = :id");
$checkUser->execute([':id' => $tenantId]);
$statusRowFail = $checkUser->fetch(PDO::FETCH_ASSOC);

assertCondition(
    "Low confidence (< 70%) returns 422 with message 'Face scan does not match the ID photo.'",
    isset($lowJson['status']) && $lowJson['status'] === 'error' &&
    str_contains($lowJson['message'] ?? '', 'Face scan does not match')
);

assertCondition(
    "Low confidence (< 70%) strictly does NOT update database (is_verified remains 0)",
    (int)$statusRowFail['is_verified'] === 0 && $statusRowFail['verification_status'] !== 'verified'
);

// Test 4.3: High confidence (>= 70%) after Phase 3 liveness auto-detection
$highOutput = shell_exec('php ' . escapeshellarg(__DIR__ . '/run_subtest.php') . ' high');
$highJson = json_decode((string)$highOutput, true);

$checkUser->execute([':id' => $tenantId]);
$statusRowPass = $checkUser->fetch(PDO::FETCH_ASSOC);

assertCondition(
    "High confidence (>= 70%) returns success response",
    isset($highJson['status']) && $highJson['status'] === 'success' && (int)$highJson['is_verified'] === 1
);

assertCondition(
    "High confidence (>= 70%) successfully marks user as verified in database (is_verified = 1, verification_status = 'verified')",
    (int)$statusRowPass['is_verified'] === 1 && $statusRowPass['verification_status'] === 'verified'
);

echo "\n========================================================================\n";
echo "SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
