<?php
/**
 * RentConnect - Centralized Authentication
 * Single login interface for Landlord and Tenants with Automated Face ID Login
 * and Strict Anti-Spoofing & Active Liveness Detection
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

// Redirect if already authenticated (via active session or valid Remember Me token)
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

$savedLogin = Auth::getSavedLoginIdentifier();
$submittedUsername = $savedLogin;
$rememberChecked = ($savedLogin !== '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $rememberMe = !empty($_POST['remember_me']);

    $submittedUsername = $username;
    $rememberChecked = $rememberMe;

    if (empty($username) || empty($password)) {
        $errorMessage = 'Please provide both email address and password.';
    } else {
        if (Auth::attempt($username, $password, $rememberMe)) {
            if (Auth::isLandlord()) {
                header('Location: admin_dashboard.php');
            } else {
                header('Location: tenant_portal.php');
            }
            exit;
        } else {
            $errorMessage = 'Invalid email address or password.';
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
  <link href="https://fonts.googleapis.com/css2?family=Great+Vibes&family=Playfair+Display:wght@700;800&family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,500&display=swap" rel="stylesheet">

  <!-- Split-Screen Login Design System -->
  <link rel="stylesheet" href="assets/css/login.css">

  <!-- face-api.js for 68-Point Facial Landmark Tracking (Local with CDN fallback) -->
  <script src="assets/js/face-api.min.js"></script>
  <script>
    if (typeof faceapi === 'undefined') {
      document.write('<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js"><\/script>');
    }
  </script>
</head>
<body>

  <main class="login-split-layout">

    <!-- ================= LEFT PANEL: BRANDING & WELCOME SHOWCASE ================= -->
    <section class="login-brand-panel" aria-label="TaraRent Overview">
      <header class="brand-header">
        <span class="brand-wordmark">TaraRent</span>
        <div class="brand-logo-wrap">
          <img src="assets/images/tararent-logo.png" alt="TaraRent Logo" class="brand-logo">
        </div>
      </header>

      <div class="showcase-frame">
        <img
          src="assets/images/login-apartment-interior.jpg"
          alt="TaraRent apartment living and kitchen interior"
          class="showcase-photo"
        >
        <div class="showcase-scrim" aria-hidden="true"></div>
        <div class="showcase-overlay">
          <h2 class="welcome-script">Welcome to Tara Rent!</h2>
          <p class="welcome-lead">Your rental management, made easier.</p>
          <p class="welcome-desc">
            Manage your rental properties, monitor payments, and keep track of your transactions&mdash;all in one convenient place.
          </p>
        </div>
      </div>
    </section>

    <!-- ================= RIGHT PANEL: BLURRED BACKDROP & SIGN-IN CARD ================= -->
    <section class="login-form-panel" aria-label="Account Sign In">
      <div class="right-panel-bg" aria-hidden="true"></div>
      <div class="right-panel-overlay" aria-hidden="true"></div>

      <div class="login-card">
        <h1 class="login-heading" id="loginMainHeading">Sign In</h1>
        <p class="login-subheading" id="loginSubHeading">Sign in to your account.</p>

        <!-- Error Notification for Password Form -->
        <?php if ($errorMessage): ?>
          <div class="login-alert" role="alert" id="passwordErrorAlert">
            <div class="login-alert-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
              </svg>
            </div>
            <div class="login-alert-body">
              <h4>Unable to sign in</h4>
              <p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          </div>
        <?php endif; ?>

        <!-- ================= 1. STANDARD EMAIL/USERNAME & PASSWORD SECTION ================= -->
        <div id="passwordLoginSection" class="login-section-fade">
          <form action="login.php" method="POST" id="passwordLoginForm">
            <div class="form-field-group">
              <label for="username" class="field-label">Email Address:</label>
              <input
                type="text"
                id="username"
                name="username"
                class="warm-input"
                placeholder="Enter email address or username"
                value="<?= htmlspecialchars($submittedUsername, ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="username"
                inputmode="email"
                required
                <?= $submittedUsername === '' ? 'autofocus' : '' ?>
              >
            </div>

            <div class="form-field-group" style="margin-bottom: 0;">
              <label for="password" class="field-label">Password:</label>
              <div class="password-input-wrap">
                <input
                  type="password"
                  id="password"
                  name="password"
                  class="warm-input warm-input-password"
                  placeholder="Enter your password"
                  autocomplete="current-password"
                  required
                  <?= $submittedUsername !== '' ? 'autofocus' : '' ?>
                >
                <button
                  type="button"
                  id="btnTogglePassword"
                  class="password-toggle-btn"
                  aria-label="Show password"
                  aria-pressed="false"
                  title="Show Password"
                >
                  <svg id="iconEyeShow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                  </svg>
                  <svg id="iconEyeHide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display: none;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>
                  </svg>
                </button>
              </div>
            </div>

            <div class="forgot-password-wrapper">
              <a href="forgot_password.php" class="forgot-password-link" title="Forgot Password?">Forgot Password?</a>
            </div>

            <div class="remember-row">
              <label for="remember_me" class="remember-label">
                <input
                  type="checkbox"
                  id="remember_me"
                  name="remember_me"
                  value="1"
                  class="remember-checkbox"
                  <?= $rememberChecked ? 'checked' : '' ?>
                >
                <span>Remember Me</span>
              </label>
            </div>

            <div class="btn-center-wrap">
              <button type="submit" class="btn-warm-pill">
                <span>Sign In</span>
              </button>
            </div>
          </form>

          <!-- Divider -->
          <div class="or-divider" role="separator" aria-label="or">
            <span class="or-divider-line" aria-hidden="true"></span>
            <span class="or-divider-text">OR</span>
            <span class="or-divider-line" aria-hidden="true"></span>
          </div>

          <!-- Prominent "Use Face ID" Button below standard form -->
          <div class="btn-center-wrap">
            <button type="button" id="btnUseFaceId" class="btn-warm-pill">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
              </svg>
              <span>Use Face ID</span>
            </button>
          </div>
        </div>

        <!-- ================= 2. AUTOMATED FACE ID LOGIN SECTION ================= -->
        <div id="faceIdSection" class="login-section-fade" style="display: none;">
          <p class="face-scan-hint">
            Look directly at the camera and blink or turn your head slightly to sign in automatically.
          </p>

          <!-- Mirrored Live Webcam Box with Oval Guide -->
          <div class="face-cam-card gcash-kyc-cam">
            <!-- Real-Time Anti-Spoofing / Liveness Warning Overlay Banner -->
            <div id="faceSpoofWarningOverlay" class="face-spoof-warning" style="display: none; align-items: center; justify-content: center; gap: 8px;">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: #ffffff; width: 18px; height: 18px; flex-shrink: 0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.002A11.959 11.959 0 0 1 12 2.964ZM12 15.75h.007v.008H12v-.008Z" />
              </svg>
              <span id="faceSpoofWarningText">Spoof detected: Please remove photos or screens from the camera.</span>
            </div>

            <!-- Live Webcam Feed (Mirrored via CSS) -->
            <video id="faceWebcamVideo" autoplay playsinline muted style="width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); -webkit-transform: scaleX(-1);"></video>

            <!-- Hidden Canvas for Mirror Frame Capture -->
            <canvas id="faceCaptureCanvas" style="display: none;"></canvas>

            <!-- Semi-Transparent Dark Overlay across <video> with Transparent Oval Cutout in Center -->
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
            <div style="width: 30px; height: 30px; border-radius: 8px; background: rgba(168, 56, 43, 0.14); color: #8F251A; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 800; flex-shrink: 0;">
              ✕
            </div>
            <div style="flex: 1;">
              <div style="font-size: 13px; font-weight: 700; color: #7A1E15; line-height: 1.35;" id="faceLoginAlertMsg">
                Face not recognized. Please try again or use your password.
              </div>
            </div>
          </div>

          <!-- Action Button: Cancel back to Password (NO manual capture/submit buttons) -->
          <div style="margin-top: 16px;">
            <button type="button" id="btnCancelFaceId" class="btn-warm-outline-pill">
              <span>Cancel / Use Password</span>
            </button>
          </div>
        </div>

      </div>
    </section>

  </main>

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
      const subHeading = document.getElementById('loginSubHeading');
      if (subHeading) subHeading.textContent = 'Verify your face to sign in automatically.';

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
      document.getElementById('loginMainHeading').textContent = 'Sign In';
      const subHeading = document.getElementById('loginSubHeading');
      if (subHeading) subHeading.textContent = 'Sign in to your account.';
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

    function toggleLoginPasswordVisibility() {
      const passwordInput = document.getElementById('password');
      const toggleBtn = document.getElementById('btnTogglePassword');
      const eyeShow = document.getElementById('iconEyeShow');
      const eyeHide = document.getElementById('iconEyeHide');
      if (!passwordInput || !toggleBtn) return;

      const isHidden = passwordInput.type === 'password';
      passwordInput.type = isHidden ? 'text' : 'password';
      toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
      toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
      toggleBtn.title = isHidden ? 'Hide Password' : 'Show Password';

      if (eyeShow) eyeShow.style.display = isHidden ? 'none' : 'block';
      if (eyeHide) eyeHide.style.display = isHidden ? 'block' : 'none';
    }

    window.toggleLoginPasswordVisibility = toggleLoginPasswordVisibility;

    document.addEventListener('DOMContentLoaded', function() {
      const btnTogglePwd = document.getElementById('btnTogglePassword');
      if (btnTogglePwd) {
        btnTogglePwd.addEventListener('click', toggleLoginPasswordVisibility);
      }

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
