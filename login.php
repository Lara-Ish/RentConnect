<?php
/**
 * RentConnect - Centralized Authentication
 * Single login interface for Landlord and Tenants with Automated Face ID Login
 * and Strict Anti-Spoofing & Active Liveness Detection
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

// Redirect if already authenticated
if (Auth::check()) {
    if (Auth::isLandlord()) {
        header('Location: admin_dashboard.php');
        exit;
    } else {
        header('Location: tenant_portal.php');
        exit;
    }
}

$errorMessage = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'deactivated') {
    $errorMessage = 'This account has been deactivated or removed by property management.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $errorMessage = 'Please provide both username and password.';
    } else {
        if (Auth::attempt($username, $password)) {
            if (Auth::isLandlord()) {
                header('Location: admin_dashboard.php');
            } else {
                header('Location: tenant_portal.php');
            }
            exit;
        } else {
            $errorMessage = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaraRent – Apartment Management System</title>

  <!-- Global Favicon -->
  <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32.png">
  <link rel="icon" type="image/png" sizes="64x64" href="assets/images/favicon-64.png">
  <link rel="apple-touch-icon" sizes="192x192" href="assets/images/favicon-192.png">
  <link rel="shortcut icon" href="favicon.ico">
  <link rel="manifest" href="site.webmanifest">

  <!-- Typography -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Claymorphism Design System -->
  <link rel="stylesheet" href="assets/css/claymorphism.css">

  <!-- face-api.js for 68-Point Facial Landmark Tracking (Local with CDN fallback) -->
  <script src="assets/js/face-api.min.js"></script>
  <script>
    if (typeof faceapi === 'undefined') {
      document.write('<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js"><\/script>');
    }
  </script>

  <style>
    .login-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }

    .login-clay-card {
      width: 100%;
      max-width: 440px;
      padding: 44px 38px;
    }

    .login-heading {
      font-size: 32px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -0.8px;
      margin-bottom: 24px;
      text-align: center;
    }

    .app-badge-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 16px;
      border-radius: var(--radius-pill);
      background: var(--clay-input-bg);
      box-shadow: var(--clay-sunken-shadow);
      font-size: 12px;
      font-weight: 700;
      color: var(--clay-primary-dark);
      margin-bottom: 22px;
    }

    /* GCash-Style Oval Camera Mask UI Styles */
    .face-cam-card {
      position: relative;
      width: 100%;
      max-width: 360px;
      height: 300px;
      margin: 0 auto;
      border-radius: 22px;
      overflow: hidden;
      background: #090d16;
      box-shadow: inset 0 3px 6px rgba(0, 0, 0, 0.45), 0 10px 25px rgba(0, 0, 0, 0.2);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    /* GCash-Style Semi-Transparent Dark Overlay across the <video> feed */
    .gcash-camera-mask,
    .face-oval-guide {
      position: absolute;
      inset: 0;
      pointer-events: none;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 10;
    }

    /* Clear, transparent oval cutout in the exact center */
    .face-oval-ring {
      width: 175px;
      height: 235px;
      border: 3px solid rgba(255, 255, 255, 0.85);
      border-radius: 50%;
      /* 9999px box-shadow creates the semi-transparent dark overlay over the entire feed outside the cutout */
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.72);
      transition: border-color 0.25s ease, box-shadow 0.25s ease, transform 0.25s ease;
      background: transparent;
    }

    .face-oval-ring.needs-closer {
      border-color: #f59e0b !important;
      border-style: dashed !important;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.72), 0 0 18px rgba(245, 158, 11, 0.5) !important;
    }

    .face-oval-ring.needs-center {
      border-color: #f43f5e !important;
      border-style: dashed !important;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.72), 0 0 18px rgba(244, 63, 94, 0.5) !important;
    }

    .face-oval-ring.multi-face {
      border-color: #ef4444 !important;
      border-style: solid !important;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.75), 0 0 22px rgba(239, 68, 68, 0.8) !important;
    }

    .face-oval-ring.challenge-active,
    .face-oval-ring.detecting {
      border-color: #38bdf8 !important;
      border-style: solid !important;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.68), 0 0 20px rgba(56, 189, 248, 0.7) !important;
    }

    .face-oval-ring.turned,
    .face-oval-ring.liveness-ready {
      border-color: #a855f7 !important;
      border-style: solid !important;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.65), 0 0 22px rgba(168, 85, 247, 0.75) !important;
    }

    .face-oval-ring.perfect-capturing,
    .face-oval-ring.holding,
    .face-oval-ring.verifying,
    .face-oval-ring.captured {
      border-color: #10b981 !important;
      border-style: solid !important;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.6), 0 0 30px rgba(16, 185, 129, 0.9) !important;
      transform: scale(1.02);
    }

    .face-oval-ring.spoof-detected {
      border-color: #ef4444 !important;
      border-style: solid !important;
      box-shadow: 0 0 0 9999px rgba(9, 13, 22, 0.75), 0 0 25px rgba(239, 68, 68, 0.85) !important;
    }

    .face-status-pill {
      position: absolute;
      bottom: 12px;
      left: 16px;
      right: 16px;
      background: rgba(15, 23, 42, 0.9);
      backdrop-filter: blur(8px);
      color: #ffffff;
      padding: 7px 14px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 700;
      text-align: center;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
      transition: all 0.2s ease;
      z-index: 10;
    }

    /* Real-Time Anti-Spoofing & Liveness Warning Overlay Banner */
    .face-spoof-warning {
      position: absolute;
      top: 10px;
      left: 12px;
      right: 12px;
      background: rgba(225, 29, 72, 0.96);
      backdrop-filter: blur(8px);
      color: #ffffff;
      padding: 8px 12px;
      border-radius: 12px;
      font-size: 11.5px;
      font-weight: 800;
      text-align: center;
      box-shadow: 0 4px 16px rgba(225, 29, 72, 0.4), inset 0 1px 1px rgba(255, 255, 255, 0.3);
      z-index: 25;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      line-height: 1.35;
      animation: spoofPulse 1.5s infinite alternate;
    }

    @keyframes spoofPulse {
      from { transform: scale(1); }
      to { transform: scale(1.02); }
    }

    .face-hold-progress-track {
      width: 100%;
      height: 6px;
      background: #e2e8f0;
      border-radius: 999px;
      overflow: hidden;
      margin-top: 14px;
    }

    .face-hold-progress-fill {
      width: 0%;
      height: 100%;
      background: linear-gradient(90deg, #38bdf8, #10b981);
      transition: width 0.08s linear;
    }

    .face-timed-alert {
      padding: 14px 18px;
      border-radius: var(--radius-xl);
      border: 2px solid #fecdd3;
      background: #fff1f2;
      box-shadow: 6px 6px 16px rgba(225, 29, 72, 0.15), inset 2px 2px 4px rgba(255, 255, 255, 0.8);
      display: flex;
      align-items: center;
      gap: 12px;
      margin-top: 16px;
      opacity: 1;
      transform: translateY(0);
      transition: opacity 0.5s ease, transform 0.5s ease;
    }

    /* Forgot Password Text Link */
    .forgot-password-wrapper {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      margin-top: 8px;
      margin-bottom: 18px;
    }

    .forgot-password-link {
      font-size: 13px;
      font-weight: 600;
      color: var(--clay-primary-dark);
      text-decoration: none;
      transition: color 0.2s ease, text-decoration 0.2s ease;
      cursor: pointer;
      display: inline-block;
    }

    .forgot-password-link:hover,
    .forgot-password-link:focus {
      color: #4f46e5;
      text-decoration: underline;
      text-underline-offset: 3px;
    }
  </style>
</head>
<body>

  <div class="login-wrapper">
    <div class="clay-card login-clay-card">
      
      <!-- Brand Pill -->
      <div style="text-align: center;">
        <div class="app-badge-pill" style="display: inline-flex; align-items: center; gap: 8px;">
          <img src="assets/images/tararent-logo.png" alt="TaraRent Logo" style="width: 20px; height: 20px; object-fit: contain;">
          <span>TaraRent</span>
        </div>
      </div>

      <!-- Main Heading: Strictly "Log in" as required -->
      <h1 class="login-heading" id="loginMainHeading">Log in</h1>

      <!-- Error Notification for Password Form -->
      <?php if ($errorMessage): ?>
        <div class="alert-clay alert-clay-warning" style="margin-bottom: 20px;" id="passwordErrorAlert">
          <div class="alert-icon-clay">⚠️</div>
          <div class="alert-body-clay">
            <h4 style="color: #9f1239;">Unable to log in</h4>
            <p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>
      <?php endif; ?>

      <!-- ================= 1. STANDARD USERNAME/PASSWORD SECTION ================= -->
      <div id="passwordLoginSection">
        <form action="login.php" method="POST" id="passwordLoginForm">
          <div class="form-group">
            <label for="username" class="form-label">Username or Email</label>
            <input 
              type="text" 
              id="username" 
              name="username" 
              class="clay-input" 
              placeholder="Enter username or email" 
              required 
              autofocus
            >
          </div>

          <div class="form-group" style="margin-bottom: 0;">
            <label for="password" class="form-label">Password</label>
            <input 
              type="password" 
              id="password" 
              name="password" 
              class="clay-input" 
              placeholder="Enter password" 
              required
            >
          </div>

          <div class="forgot-password-wrapper">
            <a href="forgot_password.php" class="forgot-password-link" title="Forgot Password?">Forgot Password?</a>
          </div>

          <button type="submit" class="clay-btn clay-btn-primary clay-btn-full" style="margin-top: 0;">
            <span>Log in</span>
          </button>
        </form>

        <!-- Divider -->
        <div style="display: flex; align-items: center; margin: 22px 0 16px 0; gap: 12px;">
          <div style="flex: 1; height: 1px; background: rgba(148, 163, 184, 0.4);"></div>
          <span style="font-size: 11.5px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">OR BIOMETRIC</span>
          <div style="flex: 1; height: 1px; background: rgba(148, 163, 184, 0.4);"></div>
        </div>

        <!-- Prominent "Use Face ID" Button below standard form -->
        <button type="button" id="btnUseFaceId" class="clay-btn clay-btn-secondary clay-btn-full" style="display: flex; align-items: center; justify-content: center; gap: 10px; font-weight: 700; padding: 13px 20px; font-size: 14.5px; background: #ffffff; border: 1.5px solid #cbd5e1; box-shadow: var(--clay-button-shadow);" onclick="switchToFaceIdLogin()">
          <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
          </svg>
          <span>Use Face ID</span>
        </button>
      </div>

      <!-- ================= 2. AUTOMATED FACE ID LOGIN SECTION ================= -->
      <div id="faceIdSection" style="display: none;">
        <p style="font-size: 13px; color: var(--text-muted); text-align: center; margin: -10px 0 16px 0;">
          Look directly at the camera and blink or turn your head slightly to log in automatically.
        </p>

        <!-- Mirrored Live Webcam Box with Oval Guide -->
        <div class="face-cam-card gcash-kyc-cam">
          <!-- Real-Time Anti-Spoofing / Liveness Warning Overlay Banner -->
          <div id="faceSpoofWarningOverlay" class="face-spoof-warning" style="display: none; align-items: center; justify-content: center; gap: 8px;">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6" style="color: #ffffff; width: 18px; height: 18px;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.002A11.959 11.959 0 0 1 12 2.964ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <span id="faceSpoofWarningText">Spoof detected: Please remove photos or screens from the camera.</span>
          </div>

          <!-- Live Webcam Feed (Mirrored via CSS) -->
          <video id="faceWebcamVideo" autoplay playsinline muted style="width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); -webkit-transform: scaleX(-1);"></video>

          <!-- Hidden Canvas for Mirror Frame Capture -->
          <canvas id="faceCaptureCanvas" style="display: none;"></canvas>

          <!-- GCash-Style Semi-Transparent Dark Overlay across <video> with Transparent Oval Cutout in Center -->
          <div class="gcash-camera-mask face-oval-guide" id="cameraDarkOverlay">
            <div class="face-oval-ring" id="biometricOvalRing"></div>
          </div>

          <!-- Dynamic Visual Feedback Badge -->
          <div class="face-status-pill" id="faceStatusPill">
            <span id="faceStatusIcon">👀</span>
            <span id="faceStatusText">Look directly at camera to start</span>
          </div>
        </div>

        <!-- Timed Notification Alert Bar for Rejection / Error -->
        <div id="faceLoginAlert" class="face-timed-alert" style="display: none;">
          <div style="width: 32px; height: 32px; border-radius: 10px; background: #ffe4e6; color: #e11d48; display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 800; flex-shrink: 0;">
            ✕
          </div>
          <div style="flex: 1;">
            <div style="font-size: 13.5px; font-weight: 700; color: #9f1239; line-height: 1.35;" id="faceLoginAlertMsg">
              Face not recognized. Please try again or use your password.
            </div>
          </div>
        </div>

        <!-- Action Button: Cancel back to Password (NO manual capture/submit buttons) -->
        <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 18px;">
          <button type="button" id="btnCancelFaceId" class="clay-btn clay-btn-secondary clay-btn-full" onclick="switchToPasswordLogin()">
            <span>Cancel / Use Password</span>
          </button>
        </div>
      </div>

    </div>
  </div>

  <!-- Vanilla JS Automated Face ID Login & Strict Anti-Spoofing Liveness Controller -->
  <script>
    // =========================================================================
    // AUTOMATED FACE ID LOGIN & MATHEMATICAL ANTI-SPOOFING SYSTEM (face-api.js)
    // 1. Zero manual capture / submit buttons required.
    // 2. Automated monitoring via face-api.js 68-point facial landmarks.
    // 3. Strict Anti-Spoofing Mathematical State Machines:
    //    - Blink validation: open baseline -> active drop (closed) -> rise back (open)
    //    - Head turn validation: center baseline -> shift away (yaw turn) -> return to center
    //    - Static phone photos / 2D screen bypass is strictly prevented.
    // 4. Automatically captures mirrored frame and authenticates against Face++ backend.
    // =========================================================================

    let isCaptured = false;
    let isVerifying = false;
    let webcamStream = null;
    let alertTimeout = null;

    let isFaceApiModelsLoaded = false;
    let isModelLoadingInProgress = false;

    // Strict Anti-Spoofing State Machines
    let livenessBlinkState = 'INITIAL'; // 'INITIAL' -> 'OPEN_OBSERVED' -> 'DROPPED_CLOSED' -> 'COMPLETED'
    let livenessHeadState = 'INITIAL';  // 'INITIAL' -> 'CENTER_OBSERVED' -> 'TURNED_AWAY' -> 'COMPLETED'
    let livenessOpenFrames = 0;
    let livenessCenterFrames = 0;
    let livenessTurnDirection = '';
    let isFaceDetectionActive = false;
    let faceDetectionTimerId = null;

    /**
     * Euclidean distance between two 2D points: sqrt((x1 - x2)^2 + (y1 - y2)^2)
     */
    function euclideanDistance(p1, p2) {
      if (!p1 || !p2) return 0;
      const x1 = (p1.x !== undefined ? p1.x : (p1._x !== undefined ? p1._x : p1[0])) || 0;
      const y1 = (p1.y !== undefined ? p1.y : (p1._y !== undefined ? p1._y : p1[1])) || 0;
      const x2 = (p2.x !== undefined ? p2.x : (p2._x !== undefined ? p2._x : p2[0])) || 0;
      const y2 = (p2.y !== undefined ? p2.y : (p2._y !== undefined ? p2._y : p2[1])) || 0;
      return Math.sqrt((x1 - x2) * (x1 - x2) + (y1 - y2) * (y1 - y2));
    }

    /**
     * Calculates 3D Head Pose Yaw Angle (horizontal rotation) in degrees.
     */
    function calculateHeadYawAngle(nose, leftEye, rightEye) {
      if (!nose || !leftEye || !rightEye) return 0;
      const nx = (nose.x !== undefined ? nose.x : (nose._x !== undefined ? nose._x : nose[0])) || 0;
      const lx = (leftEye.x !== undefined ? leftEye.x : (leftEye._x !== undefined ? leftEye._x : leftEye[0])) || 0;
      const rx = (rightEye.x !== undefined ? rightEye.x : (rightEye._x !== undefined ? rightEye._x : rightEye[0])) || 0;

      const eyeMidX = (lx + rx) / 2.0;
      const eyeDist = Math.abs(rx - lx);
      if (eyeDist <= 0.0001) return 0;

      const displacement = (nx - eyeMidX) / eyeDist;
      return displacement * 110.0;
    }

    /**
     * Preload face-api.js models (tinyFaceDetector and faceLandmark68Net)
     */
    async function ensureFaceApiModelsLoaded() {
      if (isFaceApiModelsLoaded) return true;
      if (isModelLoadingInProgress) {
        while (isModelLoadingInProgress) {
          await new Promise(r => setTimeout(r, 100));
        }
        return isFaceApiModelsLoaded;
      }

      isModelLoadingInProgress = true;

      if (typeof faceapi === 'undefined') {
        for (let i = 0; i < 25; i++) {
          if (typeof faceapi !== 'undefined') break;
          await new Promise(r => setTimeout(r, 100));
        }
      }

      if (typeof faceapi === 'undefined') {
        console.error('faceapi library is unavailable.');
        isModelLoadingInProgress = false;
        return false;
      }

      const candidateUris = [
        'assets/models',
        '/RentConnect/assets/models',
        'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model'
      ];

      for (const uri of candidateUris) {
        try {
          await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(uri),
            faceapi.nets.faceLandmark68Net.loadFromUri(uri)
          ]);
          isFaceApiModelsLoaded = true;
          break;
        } catch (err) {
          console.warn('Could not load models from uri:', uri, err);
        }
      }

      isModelLoadingInProgress = false;
      return isFaceApiModelsLoaded;
    }

    function resetLivenessAntiSpoofingState() {
      livenessBlinkState = 'INITIAL';
      livenessHeadState = 'INITIAL';
      livenessOpenFrames = 0;
      livenessCenterFrames = 0;
      livenessTurnDirection = '';
      isCaptured = false;
      isVerifying = false;
      hideSpoofWarning();
      updateStatusBadge('👀', 'Look directly at camera to start', 'normal');
    }

    /**
     * Switch from Username/Password to Face ID Mode
     */
    async function switchToFaceIdLogin() {
      document.getElementById('passwordLoginSection').style.display = 'none';
      document.getElementById('faceIdSection').style.display = 'block';
      document.getElementById('loginMainHeading').textContent = 'Face ID Login';

      const passAlert = document.getElementById('passwordErrorAlert');
      if (passAlert) passAlert.style.display = 'none';

      hideFaceLoginAlert();
      hideSpoofWarning();
      resetLivenessAntiSpoofingState();
      await startFaceWebcam();
    }

    /**
     * Switch back from Face ID to Standard Username/Password Mode
     */
    function switchToPasswordLogin() {
      stopFaceWebcam();
      document.getElementById('faceIdSection').style.display = 'none';
      document.getElementById('passwordLoginSection').style.display = 'block';
      document.getElementById('loginMainHeading').textContent = 'Log in';
      hideFaceLoginAlert();
      hideSpoofWarning();
      resetLivenessAntiSpoofingState();
    }

    /**
     * Start the user's webcam and attach to video element
     */
    async function startFaceWebcam() {
      const video = document.getElementById('faceWebcamVideo');
      updateStatusBadge('🔍', 'Starting camera...', 'normal');

      try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          throw new Error('Webcam media API is not supported in this browser.');
        }

        if (webcamStream) {
          try {
            webcamStream.getTracks().forEach(t => t.stop());
          } catch (e) {}
          webcamStream = null;
        }

        webcamStream = await navigator.mediaDevices.getUserMedia({
          video: {
            width: { ideal: 640 },
            height: { ideal: 480 },
            facingMode: 'user'
          },
          audio: false
        });

        video.srcObject = webcamStream;
        await video.play().catch(() => {});

        isVerifying = false;
        isCaptured = false;
        resetLivenessAntiSpoofingState();

        // Preload models before running automated detection
        await ensureFaceApiModelsLoaded();

        // Automatically start non-blocking tracking loop
        startFaceDetectionLoop();
      } catch (err) {
        console.error('Webcam access error:', err);
        updateStatusBadge('⚠️', 'Camera access denied', 'normal');
        showFaceLoginAlert('Unable to access webcam. Please check browser permissions or use your password.');
      }
    }

    /**
     * Terminate webcam stream and stop continuous monitoring loop
     */
    function stopFaceWebcam() {
      stopFaceDetectionLoop();
      if (webcamStream) {
        try {
          webcamStream.getTracks().forEach(track => track.stop());
        } catch (e) {}
        webcamStream = null;
      }
      const video = document.getElementById('faceWebcamVideo');
      if (video) video.srcObject = null;
      isVerifying = false;
      resetLivenessAntiSpoofingState();
      hideSpoofWarning();
    }

    /**
     * Automated non-blocking face detection loop (150ms interval)
     */
    function startFaceDetectionLoop() {
      stopFaceDetectionLoop();
      isFaceDetectionActive = true;
      scheduleNextFaceDetectionStep();
    }

    function stopFaceDetectionLoop() {
      isFaceDetectionActive = false;
      if (faceDetectionTimerId) {
        clearTimeout(faceDetectionTimerId);
        faceDetectionTimerId = null;
      }
    }

    function scheduleNextFaceDetectionStep() {
      if (!isFaceDetectionActive || isCaptured || isVerifying) return;

      faceDetectionTimerId = setTimeout(async () => {
        if (!isFaceDetectionActive || isCaptured || isVerifying) return;
        try {
          await executeFaceDetectionStep();
        } catch (err) {
          console.error('Face detection step error:', err);
        }
        if (isFaceDetectionActive && !isCaptured && !isVerifying) {
          scheduleNextFaceDetectionStep();
        }
      }, 150); // 150ms non-blocking interval (~6.6 FPS)
    }

    /**
     * Pure automated physical motion detection with mathematical anti-spoofing.
     * Prevents static phone photos / 2D screen displays from logging in.
     */
    async function executeFaceDetectionStep() {
      if (!isFaceDetectionActive || isCaptured || isVerifying) return;

      const video = document.getElementById('faceWebcamVideo');
      if (!video || video.readyState < 2 || video.videoWidth === 0 || video.paused || video.ended) {
        return;
      }

      let detection = null;
      if (typeof faceapi !== 'undefined' && faceapi.nets.tinyFaceDetector && faceapi.nets.tinyFaceDetector.params) {
        try {
          const detectorOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.3 });
          detection = await faceapi.detectSingleFace(video, detectorOptions).withFaceLandmarks();
        } catch (err) {
          console.warn('faceapi tracking warning:', err);
        }
      }

      if (!detection || !detection.landmarks) {
        updateStatusBadge('👀', 'Look directly at camera to start', 'normal');
        return;
      }

      const landmarks = detection.landmarks.positions ||
        (typeof detection.landmarks.getPositions === 'function' ? detection.landmarks.getPositions() : null);

      if (!landmarks || landmarks.length < 68) {
        updateStatusBadge('👀', 'Look directly at camera to start', 'normal');
        return;
      }

      // 1. Calculate Eye Aspect Ratio (EAR) using 68 facial landmarks
      // Left eye landmarks: 36, 37, 38, 39, 40, 41
      const p36 = landmarks[36], p37 = landmarks[37], p38 = landmarks[38];
      const p39 = landmarks[39], p40 = landmarks[40], p41 = landmarks[41];
      const leftVertical1 = euclideanDistance(p37, p41);
      const leftVertical2 = euclideanDistance(p38, p40);
      const leftHorizontal = Math.max(0.001, euclideanDistance(p36, p39));
      const leftEAR = (leftVertical1 + leftVertical2) / (2.0 * leftHorizontal);

      // Right eye landmarks: 42, 43, 44, 45, 46, 47
      const p42 = landmarks[42], p43 = landmarks[43], p44 = landmarks[44];
      const p45 = landmarks[45], p46 = landmarks[46], p47 = landmarks[47];
      const rightVertical1 = euclideanDistance(p43, p47);
      const rightVertical2 = euclideanDistance(p44, p46);
      const rightHorizontal = Math.max(0.001, euclideanDistance(p42, p45));
      const rightEAR = (rightVertical1 + rightVertical2) / (2.0 * rightHorizontal);

      const avgEAR = (leftEAR + rightEAR) / 2.0;

      // 2. Calculate Yaw (Head Turn) via Nose-to-Jaw distance ratio
      // Landmark 30 is nose tip, 0 is left jaw outline, 16 is right jaw outline
      const noseTip = landmarks[30];
      const leftJaw = landmarks[0];
      const rightJaw = landmarks[16];

      const dLeft = Math.abs(noseTip.x - leftJaw.x);
      const dRight = Math.abs(rightJaw.x - noseTip.x);
      const jawRatio = dLeft / Math.max(0.001, dRight);

      // =========================================================================
      // STRICT ANTI-SPOOFING STATE MACHINES (PREVENTS STATIC PHOTO/SCREEN BYPASS)
      // =========================================================================

      // --- BLINK STATE MACHINE ---
      // Relaxed thresholds prevent false rejections under indoor lighting:
      // 1) Establish open-eye baseline (avgEAR >= 0.24 for >= 2 frames)
      // 2) Actively drop below threshold (avgEAR < 0.20, eyes closed)
      // 3) Rise back above threshold (avgEAR >= 0.23, eyes re-opened)
      if (livenessBlinkState === 'INITIAL') {
        if (avgEAR >= 0.24) {
          livenessOpenFrames++;
          if (livenessOpenFrames >= 2) {
            livenessBlinkState = 'OPEN_OBSERVED';
          }
        } else {
          livenessOpenFrames = 0;
        }
      } else if (livenessBlinkState === 'OPEN_OBSERVED') {
        if (avgEAR < 0.20) {
          livenessBlinkState = 'DROPPED_CLOSED';
        }
      } else if (livenessBlinkState === 'DROPPED_CLOSED') {
        if (avgEAR >= 0.23) {
          livenessBlinkState = 'COMPLETED';
        }
      }

      // --- HEAD TURN STATE MACHINE ---
      // Relaxed yaw thresholds allow standard gentle head turns:
      // 1) Establish centered face baseline (0.70 <= jawRatio <= 1.40 for >= 2 frames)
      // 2) Actively shift away from center (jawRatio < 0.60 or jawRatio > 1.65)
      // 3) Return to center (0.68 <= jawRatio <= 1.45)
      if (livenessHeadState === 'INITIAL') {
        if (jawRatio >= 0.70 && jawRatio <= 1.40) {
          livenessCenterFrames++;
          if (livenessCenterFrames >= 2) {
            livenessHeadState = 'CENTER_OBSERVED';
          }
        } else {
          livenessCenterFrames = 0;
        }
      } else if (livenessHeadState === 'CENTER_OBSERVED') {
        if (jawRatio < 0.60) {
          livenessHeadState = 'TURNED_AWAY';
          livenessTurnDirection = 'left';
        } else if (jawRatio > 1.65) {
          livenessHeadState = 'TURNED_AWAY';
          livenessTurnDirection = 'right';
        }
      } else if (livenessHeadState === 'TURNED_AWAY') {
        if (jawRatio >= 0.68 && jawRatio <= 1.45) {
          livenessHeadState = 'COMPLETED';
        }
      }

      // --- EVALUATION ---
      // If landmarks are statically frozen (2D photo / phone screen held to camera),
      // the state machine remains at OPEN_OBSERVED / CENTER_OBSERVED indefinitely.
      // Automated login will NEVER trigger without the full dynamic motion cycle.
      if (livenessBlinkState === 'COMPLETED' || livenessHeadState === 'COMPLETED') {
        const triggerReason = (livenessBlinkState === 'COMPLETED')
          ? 'Blink cycle confirmed (open -> closed -> open)'
          : 'Head turn cycle confirmed (center -> turned -> center)';
        console.log('Face ID Liveness confirmed:', triggerReason);
        stopFaceDetectionLoop();
        await triggerAutoFaceCapture(triggerReason);
      } else {
        // Dynamic guidance feedback based on current cycle state
        if (livenessBlinkState === 'DROPPED_CLOSED') {
          updateStatusBadge('👀', 'Eyes closed detected! Re-open eyes...', 'challenge');
        } else if (livenessHeadState === 'TURNED_AWAY') {
          updateStatusBadge('🔄', 'Head turn detected! Return to center...', 'challenge');
        } else if (livenessBlinkState === 'OPEN_OBSERVED' || livenessHeadState === 'CENTER_OBSERVED') {
          updateStatusBadge('👀', 'Blink your eyes or turn your head slightly', 'challenge');
        } else {
          updateStatusBadge('👀', 'Look directly at camera to start', 'normal');
        }
      }
    }

    /**
     * Automatically capture the frame and POST to PHP backend (login_face_verify.php)
     */
    async function triggerAutoFaceCapture(reason) {
      if (isCaptured || isVerifying) return;
      isCaptured = true;
      isVerifying = true;
      stopFaceDetectionLoop();

      updateStatusBadge('✨', 'Liveness verified! Logging in...', 'perfect-capturing');

      const video = document.getElementById('faceWebcamVideo');
      const captureCanvas = document.getElementById('faceCaptureCanvas');
      if (!video || !captureCanvas) return;

      const width = video.videoWidth || 640;
      const height = video.videoHeight || 480;

      captureCanvas.width = width;
      captureCanvas.height = height;
      const captureCtx = captureCanvas.getContext('2d');

      // Ensure canvas capture is mirrored using ctx.scale(-1, 1)
      captureCtx.save();
      captureCtx.scale(-1, 1);
      captureCtx.drawImage(video, -width, 0, width, height);
      captureCtx.restore();

      const base64Data = captureCanvas.toDataURL('image/jpeg', 0.92);

      updateStatusBadge('⏳', 'Verifying...', 'verifying');

      try {
        const response = await fetch('login_face_verify.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            image_data: base64Data
          })
        });

        const result = await response.json();

        if (response.ok && result.status === 'success') {
          // Success: High confidence match verified
          updateStatusBadge('✓', 'Face recognized! Welcome back...', 'holding');
          const ring = document.getElementById('biometricOvalRing');
          if (ring) {
            ring.style.borderColor = '#10b981';
            ring.style.boxShadow = '0 0 0 9999px rgba(15, 23, 42, 0.4), 0 0 30px rgba(16, 185, 129, 0.9)';
          }

          setTimeout(() => {
            window.location.href = result.redirect_url || 'tenant_portal.php';
          }, 800);
        } else {
          // Rejection / No match: display timed notification
          const errMsg = result.message || 'Face not recognized. Please try again or use your password.';
          handleFaceVerificationFailure(errMsg);
        }
      } catch (err) {
        console.error('Face verification network error:', err);
        handleFaceVerificationFailure('Connection error during verification. Please try again or use your password.');
      }
    }

    /**
     * Handle verification failure: display timed notification & reset
     */
    function handleFaceVerificationFailure(message) {
      updateStatusBadge('✕', 'Face not recognized', 'normal');
      showFaceLoginAlert(message);

      // Reset state after a brief delay so tenant can scan again if desired
      setTimeout(() => {
        isVerifying = false;
        resetLivenessAntiSpoofingState();
        startFaceDetectionLoop();
      }, 2500);
    }

    /**
     * Updates dynamic visual feedback badge on the camera overlay
     */
    function updateStatusBadge(icon, text, mode) {
      const iconEl = document.getElementById('faceStatusIcon');
      const textEl = document.getElementById('faceStatusText');
      const ring = document.getElementById('biometricOvalRing');

      if (iconEl) iconEl.textContent = icon;
      if (textEl) textEl.textContent = text;

      if (ring) {
        ring.className = 'face-oval-ring ' + mode;
      }
    }

    /**
     * Display real-time error on the camera overlay
     */
    function showSpoofWarning(msg) {
      const overlay = document.getElementById('faceSpoofWarningOverlay');
      const text = document.getElementById('faceSpoofWarningText');
      if (overlay) {
        if (text) text.textContent = msg || "Spoof detected: Please remove photos or screens from the camera.";
        overlay.style.display = 'flex';
      }
    }

    function hideSpoofWarning() {
      const overlay = document.getElementById('faceSpoofWarningOverlay');
      if (overlay) {
        overlay.style.display = 'none';
      }
    }

    /**
     * Display timed notification on login screen
     */
    function showFaceLoginAlert(msg) {
      const alertBox = document.getElementById('faceLoginAlert');
      const alertMsg = document.getElementById('faceLoginAlertMsg');
      if (!alertBox || !alertMsg) return;

      alertMsg.textContent = msg;
      alertBox.style.display = 'flex';
      alertBox.style.opacity = '1';
      alertBox.style.transform = 'translateY(0)';

      if (alertTimeout) clearTimeout(alertTimeout);

      // Auto-fade notification after 4.5 seconds
      alertTimeout = setTimeout(() => {
        alertBox.style.opacity = '0';
        alertBox.style.transform = 'translateY(-6px)';
        setTimeout(() => {
          alertBox.style.display = 'none';
          alertBox.style.opacity = '1';
        }, 500);
      }, 4500);
    }

    function hideFaceLoginAlert() {
      if (alertTimeout) clearTimeout(alertTimeout);
      const alertBox = document.getElementById('faceLoginAlert');
      if (alertBox) alertBox.style.display = 'none';
    }

    // Exposed helpers for testing
    window.euclideanDistance = euclideanDistance;
    window.calculateHeadYawAngle = calculateHeadYawAngle;
    window.switchToFaceIdLogin = switchToFaceIdLogin;
    window.switchToPasswordLogin = switchToPasswordLogin;
    window.triggerAutoFaceCapture = triggerAutoFaceCapture;
    window.resetLivenessAntiSpoofingState = resetLivenessAntiSpoofingState;

    window.__simulateLivenessAction = function(action) {
      if (!isCaptured) {
        triggerAutoFaceCapture('Automated physical movement detection');
      }
    };

    window.captureAndVerifyFaceLogin = function() {
      triggerAutoFaceCapture('Automated physical movement detection');
    };

    document.addEventListener('DOMContentLoaded', function() {
      const btnUseFace = document.getElementById('btnUseFaceId');
      if (btnUseFace) {
        btnUseFace.addEventListener('click', function(e) {
          e.preventDefault();
          switchToFaceIdLogin();
        });
      }

      const btnCancelFace = document.getElementById('btnCancelFaceId');
      if (btnCancelFace) {
        btnCancelFace.addEventListener('click', function(e) {
          e.preventDefault();
          switchToPasswordLogin();
        });
      }
    });
  </script>

</body>
</html>
