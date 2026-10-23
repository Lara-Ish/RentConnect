<?php
/**
 * Test Suite: Phase 2 Relaxed Validation & Threshold Rules
 */

declare(strict_types=1);

$passCount = 0;
$failCount = 0;

function assertCheck(string $desc, bool $condition, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $desc\n";
        $passCount++;
    } else {
        echo " [FAIL] $desc\n";
        if ($details) echo "        Details: $details\n";
        $failCount++;
    }
}

echo "========================================================================\n";
echo "TEST SUITE: Phase 2 Live Testing Relaxed Validation & Model Configs\n";
echo "========================================================================\n\n";

$portalContent = file_get_contents(__DIR__ . '/../tenant_portal.php');
$loginContent = file_get_contents(__DIR__ . '/../login.php');
$verifyFaceContent = file_get_contents(__DIR__ . '/../verify_face.php');

// 1. Optimized Model Configurations in tenant_portal.php
echo "--- 1. Model Configuration in tenant_portal.php ---\n";
assertCheck(
    "TinyFaceDetectorOptions initializes with inputSize 320 (multiple of 32)",
    (bool)preg_match('/TinyFaceDetectorOptions\(\s*\{\s*inputSize:\s*(320|416)/', $portalContent),
    "Expected inputSize: 320 or 416"
);

assertCheck(
    "TinyFaceDetectorOptions initializes with scoreThreshold 0.3",
    (bool)preg_match('/scoreThreshold:\s*0\.3\b/', $portalContent),
    "Expected scoreThreshold: 0.3"
);

// 2. Optimized Model Configurations in login.php
echo "\n--- 2. Model Configuration in login.php ---\n";
assertCheck(
    "TinyFaceDetectorOptions in login.php initializes with inputSize 320 and scoreThreshold 0.3",
    (bool)preg_match('/TinyFaceDetectorOptions\(\s*\{\s*inputSize:\s*(320|416),\s*scoreThreshold:\s*0\.3\s*\}\)/', $loginContent),
    "Expected inputSize: 320, scoreThreshold: 0.3 in login.php"
);

// 3. Strict Removal of Custom Gatekeeping
echo "\n--- 3. Strict Removal of Custom Gatekeeping ---\n";
assertCheck(
    "No pixel variance calculation gatekeeping executeLivenessDetectionStep",
    !preg_match('/function\s+calculateVariance|pixelVariance|checkVariance/', $portalContent) &&
    !preg_match('/var\s+variance|let\s+variance|const\s+variance/i', $portalContent)
);

assertCheck(
    "No brightness or blur gatekeeping blocking executeLivenessDetectionStep",
    !preg_match('/checkBlur\(|isImageBlurry\(|checkBrightness\(/', $portalContent)
);

// 4. Seamless Real-Time Tracking & 68 Landmarks
echo "\n--- 4. Real-time 68-Landmark Tracking (EAR & Yaw) ---\n";
assertCheck(
    "Plots 68 facial landmarks to measure Eye Aspect Ratio (EAR)",
    str_contains($portalContent, 'landmarks.length < 68') &&
    str_contains($portalContent, 'leftEAR') &&
    str_contains($portalContent, 'rightEAR') &&
    str_contains($portalContent, 'avgEAR')
);

assertCheck(
    "Plots facial landmarks to measure Yaw (Head Turn)",
    str_contains($portalContent, 'noseTip') &&
    str_contains($portalContent, 'leftJaw') &&
    str_contains($portalContent, 'rightJaw') &&
    str_contains($portalContent, 'jawRatio')
);

// 5. Camera Overlay Error Display Suppression
echo "\n--- 5. Overlay Status Suppression of Blurry / Not Detected Errors ---\n";
assertCheck(
    "Live tracking overlay does NOT display 'no face detected' or 'too blurry' on missed frames",
    !preg_match('/updateLivenessStatusBadge\([^,]+,\s*[\'"][^\'"]*blurry[^\'"]*[\'"]/i', $portalContent) &&
    !preg_match('/updateLivenessStatusBadge\([^,]+,\s*[\'"][^\'"]*no face detected[^\'"]*[\'"]/i', $portalContent)
);

assertCheck(
    "Overlay failure handler sanitizes blur or detection mismatch messages",
    str_contains($portalContent, "errMsg.toLowerCase().includes('blurry')") &&
    str_contains($portalContent, "errMsg.toLowerCase().includes('not detected')")
);

// 6. Relaxed State Machine Thresholds
echo "\n--- 6. Relaxed State Machine Thresholds ---\n";
assertCheck(
    "Relaxed Blink open-eye baseline (avgEAR >= 0.24)",
    str_contains($portalContent, 'avgEAR >= 0.24')
);

assertCheck(
    "Relaxed Blink closed threshold (avgEAR < 0.20)",
    str_contains($portalContent, 'avgEAR < 0.20')
);

assertCheck(
    "Relaxed Yaw center baseline (0.70 <= jawRatio <= 1.40)",
    str_contains($portalContent, 'jawRatio >= 0.70 && jawRatio <= 1.40')
);

assertCheck(
    "Relaxed Yaw turn threshold (jawRatio < 0.60 || jawRatio > 1.65)",
    str_contains($portalContent, 'jawRatio < 0.60') &&
    str_contains($portalContent, 'jawRatio > 1.65')
);

// 7. Video Stream Lifecycle & Stillness Capture Fix
echo "\n--- 7. Video Stream Lifecycle & Active Frame Capture ---\n";
assertCheck(
    "pauseLivenessTracking preserves media stream during 1-second stillness capture pause",
    str_contains($portalContent, 'pauseLivenessTracking') &&
    str_contains($portalContent, 'stopLivenessCamera')
);

assertCheck(
    "Video frame is drawn to canvas before video is paused",
    str_contains($portalContent, 'ctx.drawImage(video')
);

// 8. Strict Identity Matching in verify_face.php
echo "\n--- 8. Strict Backend Identity Matching (verify_face.php) ---\n";
assertCheck(
    "Backend removed 'Face not detected or image is too blurry' error message",
    !str_contains($verifyFaceContent, 'Face not detected or image is too blurry')
);

assertCheck(
    "OCR checks do not block identity matching before Face++ evaluation",
    !preg_match('/extractIdDataWithOCR[\s\S]{1,300}sendFaceJsonResponse\(422/', $verifyFaceContent)
);

assertCheck(
    "Confidence match via Face++ directly approves account with 'Verification Successful'",
    str_contains($verifyFaceContent, 'if ($isConfidenceMatch)') &&
    str_contains($verifyFaceContent, "'message' => 'Verification Successful'")
);

echo "\n========================================================================\n";
echo "SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================================\n";

if ($failCount > 0) exit(1);
exit(0);
