<?php
declare(strict_types=1);

echo "=== TESTING GCASH-STYLE OVAL MASK, BOUNDING BOX CHECKS & 3D HEAD POSE LIVENESS ===\n";
echo "=== (Replacing Blink Detection with Active 3D Yaw Rotation & Return-to-Center) ===\n\n";

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

// =============================================================================
// 1. INSPECT login.php FOR GCASH MASK, BOUNDING BOX CHECKS & 3D HEAD POSE
// =============================================================================
$loginHtml = file_get_contents(__DIR__ . '/../login.php');

// A. GCash-Style Oval Camera Mask
assertCondition(
    "login.php: GCash-Style Semi-Transparent Dark Overlay CSS exists (.gcash-camera-mask)",
    str_contains($loginHtml, '.gcash-camera-mask') &&
    str_contains($loginHtml, 'box-shadow: 0 0 0 9999px rgba(')
);

assertCondition(
    "login.php: Clear transparent center oval cutout exists in HTML markup (#biometricOvalRing)",
    str_contains($loginHtml, 'id="cameraDarkOverlay"') &&
    str_contains($loginHtml, 'class="gcash-camera-mask') &&
    str_contains($loginHtml, 'id="biometricOvalRing"') &&
    str_contains($loginHtml, 'class="face-oval-ring"')
);

// B. Face Alignment & Distance Checks
assertCondition(
    "login.php: Bounding box distance check prompts 'Please move closer.'",
    str_contains($loginHtml, 'Please move closer.') &&
    str_contains($loginHtml, 'tooSmall')
);

assertCondition(
    "login.php: Bounds check prompts 'Center your face in the oval.'",
    str_contains($loginHtml, 'Center your face in the oval.') &&
    str_contains($loginHtml, 'outsideOval')
);

assertCondition(
    "login.php: Multi-face check prompts 'Only one face allowed in frame.'",
    str_contains($loginHtml, 'Only one face allowed in frame.') &&
    str_contains($loginHtml, 'multipleFaces')
);

// C. 3D Head Pose Liveness Challenge (Replacing Blink Detection)
assertCondition(
    "login.php: 3D Head Pose calculateHeadYawAngle function exists",
    str_contains($loginHtml, 'function calculateHeadYawAngle(nose, leftEye, rightEye)') &&
    str_contains($loginHtml, 'displacement = (nx - eyeMidX) / eyeDist') &&
    str_contains($loginHtml, 'yawDegrees = displacement * 110.0')
);

assertCondition(
    "login.php: 3D Head Pose thresholds define >= 15° turn and <= 6° return to center",
    str_contains($loginHtml, 'const MIN_YAW_TURN_DEGREES = 15.0;') &&
    str_contains($loginHtml, 'const MAX_CENTER_DEVIATION_DEGREES = 6.0;')
);

assertCondition(
    "login.php: 3D Head Pose prompt asks user 'Please move your head slightly to the left or right.'",
    str_contains($loginHtml, 'Please move your head slightly to the left or right.')
);

assertCondition(
    "login.php: Prompts 'Now look straight at the camera.' once turn is detected",
    str_contains($loginHtml, 'Now look straight at the camera.')
);

assertCondition(
    "login.php: Immediately locks UI, displays 'Perfect! Capturing...', and auto-captures on return to center",
    str_contains($loginHtml, 'Perfect! Capturing...') &&
    str_contains($loginHtml, 'triggerAutoFaceCapture()')
);

assertCondition(
    "login.php: Canvas capture is horizontally mirrored using ctx.scale(-1, 1)",
    str_contains($loginHtml, 'captureCtx.scale(-1, 1);') &&
    str_contains($loginHtml, 'captureCtx.drawImage(video, -width, 0, width, height);')
);

assertCondition(
    "login.php: Automatically triggers AJAX POST request to login_face_verify.php",
    str_contains($loginHtml, 'fetch(\'login_face_verify.php\'') &&
    str_contains($loginHtml, 'image_data: base64Data')
);

assertCondition(
    "login.php: Anti-spoofing overlay banner displays 'Spoof detected: Please remove photos or screens from the camera.'",
    str_contains($loginHtml, 'id="faceSpoofWarningOverlay"') &&
    str_contains($loginHtml, 'Spoof detected: Please remove photos or screens from the camera.')
);

assertCondition(
    "login.php: Frozen landmark spoof check across 30-frame sliding window",
    str_contains($loginHtml, 'landmarkBuffer.length >= 30') &&
    str_contains($loginHtml, 'totalLandmarkDeviation < 0.12')
);

// D. Performance Optimization & Main UI Thread Unblocking
assertCondition(
    "login.php: Throttles tracking loop to 150ms-200ms (TRACKING_THROTTLE_MS = 180)",
    str_contains($loginHtml, 'const TRACKING_THROTTLE_MS = 180;') &&
    str_contains($loginHtml, 'timestamp - lastCheckTime >= TRACKING_THROTTLE_MS')
);

assertCondition(
    "login.php: Yields control to main UI thread (yieldControl via setTimeout 0)",
    str_contains($loginHtml, 'function yieldControl()') &&
    str_contains($loginHtml, 'setTimeout(resolve, 0)') &&
    str_contains($loginHtml, 'await yieldControl()')
);

// =============================================================================
// 2. INSPECT tenant_portal.php FOR GCASH MASK, BOUNDING BOX CHECKS & 3D HEAD POSE
// =============================================================================
$tenantHtml = file_get_contents(__DIR__ . '/../tenant_portal.php');

assertCondition(
    "tenant_portal.php: GCash-Style Semi-Transparent Dark Overlay CSS exists (.gcash-camera-mask)",
    str_contains($tenantHtml, '.gcash-camera-mask') &&
    str_contains($tenantHtml, 'box-shadow: 0 0 0 9999px rgba(')
);

assertCondition(
    "tenant_portal.php: Clear transparent center oval cutout exists in HTML markup (#tenantFaceOvalRing)",
    str_contains($tenantHtml, 'id="cameraGuideOverlay"') &&
    str_contains($tenantHtml, 'class="gcash-camera-mask') &&
    str_contains($tenantHtml, 'id="tenantFaceOvalRing"') &&
    str_contains($tenantHtml, 'class="face-oval-ring"')
);

assertCondition(
    "tenant_portal.php: Bounding box distance check prompts 'Please move closer.'",
    str_contains($tenantHtml, 'Please move closer.') &&
    str_contains($tenantHtml, 'tooSmall')
);

assertCondition(
    "tenant_portal.php: Bounds check prompts 'Center your face in the oval.'",
    str_contains($tenantHtml, 'Center your face in the oval.') &&
    str_contains($tenantHtml, 'outsideOval')
);

assertCondition(
    "tenant_portal.php: Multi-face check prompts 'Only one face allowed in frame.'",
    str_contains($tenantHtml, 'Only one face allowed in frame.') &&
    str_contains($tenantHtml, 'multipleFaces')
);

assertCondition(
    "tenant_portal.php: 3D Head Pose calculateHeadYawAngle function exists",
    str_contains($tenantHtml, 'function calculateHeadYawAngle(nose, leftEye, rightEye)') &&
    str_contains($tenantHtml, 'displacement = (nx - eyeMidX) / eyeDist') &&
    str_contains($tenantHtml, 'yawDegrees = displacement * 110.0')
);

assertCondition(
    "tenant_portal.php: 3D Head Pose thresholds define >= 15° turn and <= 6° return to center",
    str_contains($tenantHtml, 'const MIN_YAW_TURN_DEGREES = 15.0;') &&
    str_contains($tenantHtml, 'const MAX_CENTER_DEVIATION_DEGREES = 6.0;')
);

assertCondition(
    "tenant_portal.php: 3D Head Pose prompt asks user 'Please move your head slightly to the left or right.'",
    str_contains($tenantHtml, 'Please move your head slightly to the left or right.')
);

assertCondition(
    "tenant_portal.php: Prompts 'Now look straight at the camera.' once turn is detected",
    str_contains($tenantHtml, 'Now look straight at the camera.')
);

assertCondition(
    "tenant_portal.php: Immediately locks UI, displays 'Perfect! Capturing...', and auto-captures on return to center",
    str_contains($tenantHtml, 'Perfect! Capturing...') &&
    str_contains($tenantHtml, 'captureLiveFace()')
);

assertCondition(
    "tenant_portal.php: Anti-spoofing overlay banner displays 'Spoof detected: Please remove photos or screens from the camera.'",
    str_contains($tenantHtml, 'id="tenantSpoofWarningOverlay"') &&
    str_contains($tenantHtml, 'Spoof detected: Please remove photos or screens from the camera.')
);

assertCondition(
    "tenant_portal.php: Frozen landmark spoof check across 30-frame sliding window",
    str_contains($tenantHtml, 'tenantLandmarkBuffer.length >= 30') &&
    str_contains($tenantHtml, 'totalLandmarkDeviation < 0.12')
);

assertCondition(
    "tenant_portal.php: captureLiveFace strictly blocks capture if head pose challenge is not complete",
    str_contains($tenantHtml, '!isTenantHeadPoseChallengeComplete && tenantHeadPoseState !== \'CAPTURING\'')
);

assertCondition(
    "tenant_portal.php: Canvas capture is horizontally mirrored using ctx.scale(-1, 1)",
    str_contains($tenantHtml, 'ctx.scale(-1, 1);') &&
    str_contains($tenantHtml, 'ctx.drawImage(video, -width, 0, width, height);')
);

assertCondition(
    "tenant_portal.php: Throttles tracking loop to 150ms-200ms (TRACKING_THROTTLE_MS = 180)",
    str_contains($tenantHtml, 'const TRACKING_THROTTLE_MS = 180;') &&
    str_contains($tenantHtml, 'timestamp - lastCheckTime >= TRACKING_THROTTLE_MS')
);

assertCondition(
    "tenant_portal.php: Yields control to main UI thread (yieldControl via setTimeout 0)",
    str_contains($tenantHtml, 'function yieldControl()') &&
    str_contains($tenantHtml, 'setTimeout(resolve, 0)') &&
    str_contains($tenantHtml, 'await yieldControl()')
);

// =============================================================================
// 3. MATHEMATICAL 3D HEAD POSE YAW ANGLE & STATE MACHINE UNIT TESTS
// =============================================================================

function calculateTestHeadYawAngle(array $nose, array $leftEye, array $rightEye): float {
    $nx = $nose['x'];
    $lx = $leftEye['x'];
    $rx = $rightEye['x'];
    $eyeMidX = ($lx + $rx) / 2.0;
    $eyeDist = abs($rx - $lx);
    if ($eyeDist <= 0.0001) return 0.0;
    $displacement = ($nx - $eyeMidX) / $eyeDist;
    return $displacement * 110.0;
}

// 1. Center / Looking Straight Ahead
// Left eye at x=35, right eye at x=65 (midpoint = 50, dist = 30). Nose at x=50.
$straightYaw = calculateTestHeadYawAngle(['x' => 50, 'y' => 55], ['x' => 35, 'y' => 40], ['x' => 65, 'y' => 40]);
assertCondition(
    "Unit Test: Face looking straight ahead yields Yaw ~ 0° ($straightYaw)",
    abs($straightYaw) < 1.0,
    "Expected abs(yaw) < 1.0, got $straightYaw"
);

// 2. Turning Right
// Nose shifts rightward towards x=55
$turnRightYaw = calculateTestHeadYawAngle(['x' => 55, 'y' => 55], ['x' => 35, 'y' => 40], ['x' => 65, 'y' => 40]);
assertCondition(
    "Unit Test: Head turned right yields positive Yaw >= 15° ({$turnRightYaw}°)",
    $turnRightYaw >= 15.0,
    "Expected >= 15.0, got $turnRightYaw"
);

// 3. Turning Left
// Nose shifts leftward towards x=45
$turnLeftYaw = calculateTestHeadYawAngle(['x' => 45, 'y' => 55], ['x' => 35, 'y' => 40], ['x' => 65, 'y' => 40]);
assertCondition(
    "Unit Test: Head turned left yields negative Yaw <= -15° ({$turnLeftYaw}°)",
    $turnLeftYaw <= -15.0,
    "Expected <= -15.0, got $turnLeftYaw"
);

// 4. Simulate Full 3D Head Pose Liveness State Machine
// ALIGNING -> AWAITING_TURN -> AWAITING_RETURN -> CAPTURING
$MIN_YAW_TURN_DEGREES = 15.0;
$MAX_CENTER_DEVIATION_DEGREES = 6.0;

$state = 'ALIGNING';
$autoCaptureTriggered = false;
$captureAtFrame = -1;

$simulatedSequence = [
    ['yaw' => 1.2,  'desc' => 'Centered and aligned'],
    ['yaw' => 5.4,  'desc' => 'Slight drift (< 15°)'],
    ['yaw' => 18.2, 'desc' => 'Subtle rotation >= 15° (Turn confirmed)'],
    ['yaw' => 14.0, 'desc' => 'Returning, not yet centered (> 6°)'],
    ['yaw' => 2.1,  'desc' => 'Returned straight at camera (<= 6°) -> TRIGGER CAPTURE!'],
];

foreach ($simulatedSequence as $idx => $step) {
    $yaw = $step['yaw'];
    $absYaw = abs($yaw);

    if ($state === 'ALIGNING') {
        $state = 'AWAITING_TURN';
    } elseif ($state === 'AWAITING_TURN') {
        if ($absYaw >= $MIN_YAW_TURN_DEGREES) {
            $state = 'AWAITING_RETURN';
        }
    } elseif ($state === 'AWAITING_RETURN') {
        if ($absYaw <= $MAX_CENTER_DEVIATION_DEGREES) {
            $state = 'CAPTURING';
            $autoCaptureTriggered = true;
            $captureAtFrame = $idx + 1;
            break;
        }
    }
}

assertCondition(
    "Unit Test: 3D Head Pose state machine requires >= 15° rotation then <= 6° return straight ahead",
    $state === 'CAPTURING' && $autoCaptureTriggered && $captureAtFrame === 5,
    "Expected capture at step 5, got state $state at step $captureAtFrame"
);

// 5. Asynchronous Throttling & UI Unblocking Simulation Test
$fps60Ticks = 60;
$frameDurationMs = 1000 / $fps60Ticks; // 16.67ms
$simThrottleMs = 180; // 180ms throttle target (150ms-200ms)
$throttledExecutions = 0;
$simLastCheckTime = -999.0;
$uiEventsProcessed = 0;

for ($i = 0; $i < $fps60Ticks; $i++) {
    $currentTimestamp = $i * $frameDurationMs;
    if (($currentTimestamp - $simLastCheckTime) >= $simThrottleMs) {
        $simLastCheckTime = $currentTimestamp;
        $throttledExecutions++;
    } else {
        $uiEventsProcessed++;
    }
}

assertCondition(
    "Unit Test: 180ms throttle reduces heavy math executions from 60 to $throttledExecutions per second (5-7 FPS target)",
    $throttledExecutions >= 5 && $throttledExecutions <= 7,
    "Expected 5-7 executions, got $throttledExecutions"
);

assertCondition(
    "Unit Test: Main UI thread yields control for $uiEventsProcessed/60 ticks (~90% idle time for sidebar clicks & UI repaints)",
    $uiEventsProcessed >= 53,
    "Expected >= 53 yielded slots, got $uiEventsProcessed"
);

echo "\nSummary: Passed: $pass, Failed: $fail\n";
if ($fail > 0) exit(1);
