<?php
/**
 * RentConnect - Forgot Password Workflow
 * 
 * 4 Separate Screens (No top progress labels):
 *   1. Forgot Password – Registered Email
 *   2. Verify Your Email – 6-Digit Code
 *   3. Change Password
 *   4. Password Reset Successful
 *
 * Security & Navigation Rules:
 *   - Refreshing the page (F5 / reload) automatically returns user to login.php
 *   - Pressing browser Back button automatically returns user to login.php
 *   - Abandoned sessions invalidate MySQL verification codes and clear session state
 *   - Change Password screen (Screen 3) is strictly gated by PHP backend verification
 *   - Styled with Tailwind CSS utility classes, glassmorphism, and natural emoji indicators
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/email_service.php';
require_once __DIR__ . '/includes/notification_service.php';

// Redirect if already authenticated
if (Auth::check()) {
    if (Auth::isLandlord()) {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: tenant_portal.php');
    }
    exit;
}

$pdo = Database::getConnection();

// Helper: check if incoming request expects JSON
function isJsonRequest(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    return str_contains($accept, 'application/json') 
        || str_contains($contentType, 'application/json')
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
}

// -----------------------------------------------------------------------------
// ABANDONMENT / REFRESH HANDLING ON GET REQUESTS
// -----------------------------------------------------------------------------
// If explicitly abandoned via query param
if (isset($_GET['abandon']) && $_GET['abandon'] === '1') {
    if (!empty($_SESSION['forgot_pwd']['reset_id'])) {
        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL")
            ->execute([':id' => (int)$_SESSION['forgot_pwd']['reset_id']]);
    }
    unset($_SESSION['forgot_pwd'], $_SESSION['forgot_pwd_done']);
    header('Location: login.php');
    exit;
}

// If user navigates or refreshes forgot_password.php while an active/completed reset session exists:
// Invalidate code in DB, clear temporary state, and return to login.php as required
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_SESSION['forgot_pwd']) || !empty($_SESSION['forgot_pwd_done'])) {
        if (!empty($_SESSION['forgot_pwd']['reset_id'])) {
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL")
                ->execute([':id' => (int)$_SESSION['forgot_pwd']['reset_id']]);
        }
        unset($_SESSION['forgot_pwd'], $_SESSION['forgot_pwd_done']);
        header('Location: login.php');
        exit;
    }
}

// =============================================================================
// POST ACTION HANDLERS (AJAX API & Form Submissions)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $jsonInput = json_decode($rawInput, true);
    $input = is_array($jsonInput) ? $jsonInput : $_POST;

    $action = trim((string)($input['action'] ?? 'send_code'));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // -------------------------------------------------------------------------
    // ACTION: ABANDON RESET SESSION (Called via Beacon or Popstate)
    // -------------------------------------------------------------------------
    if ($action === 'abandon') {
        if (!empty($_SESSION['forgot_pwd']['reset_id'])) {
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL")
                ->execute([':id' => (int)$_SESSION['forgot_pwd']['reset_id']]);
        }
        unset($_SESSION['forgot_pwd'], $_SESSION['forgot_pwd_done']);

        if (isJsonRequest()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'success', 'message' => 'Reset process abandoned.']);
            exit;
        }
        header('Location: login.php');
        exit;
    }

    // -------------------------------------------------------------------------
    // ACTION 1: SEND VERIFICATION CODE (Screen 1 -> Screen 2)
    // -------------------------------------------------------------------------
    if ($action === 'send_code') {
        $email = trim((string)($input['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resp = ['status' => 'error', 'message' => 'Please provide a valid registered email address.'];
            if (isJsonRequest()) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode($resp);
                exit;
            }
            $initError = $resp['message'];
        } else {
            // Rate Limiting: Max 6 reset requests per IP in 15 minutes
            $rateStmt = $pdo->prepare("
                SELECT COUNT(*) FROM password_resets 
                WHERE ip_address = :ip AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $rateStmt->execute([':ip' => $ip]);
            $recentAttempts = (int)$rateStmt->fetchColumn();

            if ($recentAttempts >= 6) {
                $resp = [
                    'status' => 'error', 
                    'message' => 'Too many password reset requests from this network. Please wait a few minutes before trying again.'
                ];
                if (isJsonRequest()) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode($resp);
                    exit;
                }
                $initError = $resp['message'];
            } else {
                // Query user by registered email (active accounts only)
                $userStmt = $pdo->prepare("
                    SELECT id, name, email, role, is_active 
                    FROM users 
                    WHERE email = :email AND is_active = 1 
                    LIMIT 1
                ");
                $userStmt->execute([':email' => $email]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $resp = [
                        'status' => 'error',
                        'message' => 'No active account found with this email address. Please verify and try again.'
                    ];
                    if (isJsonRequest()) {
                        header('Content-Type: application/json; charset=UTF-8');
                        echo json_encode($resp);
                        exit;
                    }
                    $initError = $resp['message'];
                } else {
                    $userId = (int)$user['id'];

                    // Invalidate prior active verification codes for this user
                    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")
                        ->execute([':uid' => $userId]);

                    // Generate cryptographically secure 6-digit numeric verification code
                    $verificationCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $codeHash = hash('sha256', $verificationCode);

                    // Store hashed code with 5-minute expiry & max 5 attempts
                    $insertStmt = $pdo->prepare("
                        INSERT INTO password_resets 
                            (user_id, email, token_hash, ip_address, expires_at, attempts, max_attempts, created_at)
                        VALUES 
                            (:uid, :email, :thash, :ip, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 5, NOW())
                    ");
                    $insertStmt->execute([
                        ':uid'   => $userId,
                        ':email' => $user['email'],
                        ':thash' => $codeHash,
                        ':ip'    => $ip
                    ]);
                    $resetId = (int)$pdo->lastInsertId();

                    // Dispatch email dynamically to registered address
                    EmailService::sendPasswordResetCode(
                        $pdo,
                        (string)$user['email'],
                        (string)$user['name'],
                        $verificationCode,
                        5,
                        $userId
                    );

                    // Store temporary session state
                    $_SESSION['forgot_pwd'] = [
                        'email'        => (string)$user['email'],
                        'name'         => (string)$user['name'],
                        'user_id'      => $userId,
                        'reset_id'     => $resetId,
                        'code_sent_at' => time(),
                        'verified'     => false,
                        'verified_at'  => null,
                        'reset_token'  => null,
                        'screen'       => 2
                    ];

                    $resp = [
                        'status'  => 'success',
                        'message' => 'A 6-digit verification code has been sent to your registered email address.',
                        'email'   => (string)$user['email'],
                        'screen'  => 2
                    ];

                    if (isJsonRequest()) {
                        header('Content-Type: application/json; charset=UTF-8');
                        echo json_encode($resp);
                        exit;
                    }

                    header('Location: forgot_password.php');
                    exit;
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // ACTION 2: VERIFY 6-DIGIT CODE (Screen 2 -> Screen 3)
    // -------------------------------------------------------------------------
    elseif ($action === 'verify_code') {
        $email = trim((string)($input['email'] ?? ($_SESSION['forgot_pwd']['email'] ?? '')));
        $code = trim((string)($input['code'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resp = ['status' => 'error', 'message' => 'Registered email is missing or invalid.'];
        } elseif (empty($code) || !preg_match('/^[0-9]{6}$/', $code)) {
            $resp = ['status' => 'error', 'message' => 'Please enter the 6-digit numeric verification code.'];
        } else {
            $stmt = $pdo->prepare("
                SELECT pr.id, pr.user_id, pr.email, pr.token_hash, pr.expires_at, pr.used_at, 
                       pr.attempts, pr.max_attempts, u.name, u.role
                FROM password_resets pr
                JOIN users u ON pr.user_id = u.id
                WHERE pr.email = :email
                  AND pr.used_at IS NULL
                ORDER BY pr.id DESC
                LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                $resp = [
                    'status' => 'error', 
                    'message' => 'No active verification code found for this email. Please request a new code.'
                ];
            } elseif ((int)$record['attempts'] >= (int)$record['max_attempts']) {
                $resp = [
                    'status' => 'error', 
                    'message' => 'Too many failed attempts. For your security, this code is no longer valid. Please request a new code.'
                ];
            } elseif (strtotime($record['expires_at']) < time()) {
                $resp = [
                    'status' => 'error', 
                    'message' => 'This verification code has expired. Codes are only valid for 5 minutes. Please request a new code.'
                ];
            } else {
                $submittedHash = hash('sha256', $code);

                if (!hash_equals($record['token_hash'], $submittedHash)) {
                    // Increment failed attempt counter
                    $pdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = :id")
                        ->execute([':id' => $record['id']]);

                    $remaining = max(0, (int)$record['max_attempts'] - ((int)$record['attempts'] + 1));
                    $resp = [
                        'status' => 'error',
                        'message' => "Invalid verification code. Please check your email. ({$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining)",
                        'remaining_attempts' => $remaining
                    ];
                } else {
                    // Verification Succeeded! Generate Single-Use Cryptographic Reset Token
                    $resetToken = bin2hex(random_bytes(32));

                    $_SESSION['forgot_pwd'] = [
                        'email'        => (string)$record['email'],
                        'name'         => (string)$record['name'],
                        'user_id'      => (int)$record['user_id'],
                        'reset_id'     => (int)$record['id'],
                        'code_sent_at' => $_SESSION['forgot_pwd']['code_sent_at'] ?? time(),
                        'verified'     => true,
                        'verified_at'  => time(),
                        'reset_token'  => $resetToken,
                        'screen'       => 3
                    ];

                    $resp = [
                        'status'      => 'success',
                        'message'     => 'Your email has been successfully verified. You can now create a new password.',
                        'screen'      => 3,
                        'reset_token' => $resetToken
                    ];
                }
            }
        }

        if (isJsonRequest()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($resp);
            exit;
        }

        if (($resp['status'] ?? '') === 'success') {
            header('Location: forgot_password.php');
            exit;
        } else {
            $initError = $resp['message'] ?? 'Verification failed.';
        }
    }

    // -------------------------------------------------------------------------
    // ACTION 3: RESEND VERIFICATION CODE (Screen 2)
    // -------------------------------------------------------------------------
    elseif ($action === 'resend_code') {
        $email = trim((string)($input['email'] ?? ($_SESSION['forgot_pwd']['email'] ?? '')));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resp = ['status' => 'error', 'message' => 'Registered email is missing or invalid.'];
        } else {
            $lastSent = (int)($_SESSION['forgot_pwd']['code_sent_at'] ?? 0);
            $cooldown = 45;
            $timeSince = time() - $lastSent;

            if ($timeSince < $cooldown) {
                $remainingSec = $cooldown - $timeSince;
                $resp = [
                    'status' => 'error',
                    'message' => "Please wait {$remainingSec} second" . ($remainingSec === 1 ? '' : 's') . " before requesting another code.",
                    'cooldown_remaining' => $remainingSec
                ];
            } else {
                $userStmt = $pdo->prepare("
                    SELECT id, name, email, is_active 
                    FROM users 
                    WHERE email = :email AND is_active = 1 
                    LIMIT 1
                ");
                $userStmt->execute([':email' => $email]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $resp = ['status' => 'error', 'message' => 'No active account found with this email address.'];
                } else {
                    $userId = (int)$user['id'];

                    // Invalidate previous active codes
                    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")
                        ->execute([':uid' => $userId]);

                    // Generate new 6-digit code
                    $verificationCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $codeHash = hash('sha256', $verificationCode);

                    $insertStmt = $pdo->prepare("
                        INSERT INTO password_resets 
                            (user_id, email, token_hash, ip_address, expires_at, attempts, max_attempts, created_at)
                        VALUES 
                            (:uid, :email, :thash, :ip, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, 5, NOW())
                    ");
                    $insertStmt->execute([
                        ':uid'   => $userId,
                        ':email' => $user['email'],
                        ':thash' => $codeHash,
                        ':ip'    => $ip
                    ]);
                    $resetId = (int)$pdo->lastInsertId();

                    EmailService::sendPasswordResetCode(
                        $pdo,
                        (string)$user['email'],
                        (string)$user['name'],
                        $verificationCode,
                        5,
                        $userId
                    );

                    $_SESSION['forgot_pwd'] = [
                        'email'        => (string)$user['email'],
                        'name'         => (string)$user['name'],
                        'user_id'      => $userId,
                        'reset_id'     => $resetId,
                        'code_sent_at' => time(),
                        'verified'     => false,
                        'verified_at'  => null,
                        'reset_token'  => null,
                        'screen'       => 2
                    ];

                    $resp = [
                        'status'  => 'success',
                        'message' => 'A new 6-digit verification code has been sent to your email.',
                        'email'   => (string)$user['email'],
                        'screen'  => 2
                    ];
                }
            }
        }

        if (isJsonRequest()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($resp);
            exit;
        }

        header('Location: forgot_password.php');
        exit;
    }

    // -------------------------------------------------------------------------
    // ACTION 4: RESET PASSWORD (Screen 3 -> Screen 4)
    // -------------------------------------------------------------------------
    elseif ($action === 'reset_password') {
        // MANDATORY SECURITY CHECKS: Cannot bypass Screen 2 verification
        $isVerified = !empty($_SESSION['forgot_pwd']['verified']) && $_SESSION['forgot_pwd']['verified'] === true;
        $verifiedAt = (int)($_SESSION['forgot_pwd']['verified_at'] ?? 0);
        $sessionToken = (string)($_SESSION['forgot_pwd']['reset_token'] ?? '');
        $submittedToken = trim((string)($input['reset_token'] ?? ''));

        if (!$isVerified || empty($sessionToken)) {
            $resp = [
                'status'  => 'error',
                'message' => 'Unauthorized: You must verify your email with the 6-digit code before changing your password.',
                'screen'  => 2
            ];
        } elseif ((time() - $verifiedAt) > 900) { // 15-minute verification lifetime
            unset($_SESSION['forgot_pwd']);
            $resp = [
                'status'  => 'error',
                'message' => 'Your verification session has timed out. Please enter your email to request a new code.',
                'screen'  => 1
            ];
        } elseif (!hash_equals($sessionToken, $submittedToken)) {
            $resp = [
                'status'  => 'error',
                'message' => 'Invalid or expired security token. Please verify again.',
                'screen'  => 2
            ];
        } else {
            $newPassword = (string)($input['new_password'] ?? '');
            $confirmPassword = (string)($input['confirm_password'] ?? '');

            if (empty($newPassword) || strlen($newPassword) < 6) {
                $resp = ['status' => 'error', 'message' => 'New password must be at least 6 characters long.'];
            } elseif ($newPassword !== $confirmPassword) {
                $resp = ['status' => 'error', 'message' => 'The new passwords do not match. Please verify and re-type.'];
            } else {
                try {
                    $pdo->beginTransaction();

                    $userId = (int)$_SESSION['forgot_pwd']['user_id'];
                    $resetId = (int)$_SESSION['forgot_pwd']['reset_id'];
                    $userEmail = (string)$_SESSION['forgot_pwd']['email'];
                    $userName = (string)($_SESSION['forgot_pwd']['name'] ?? 'User');
                    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

                    // 1. Update user password
                    $upd = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
                    $upd->execute([':hash' => $newHash, ':id' => $userId]);

                    // 2. Mark this reset record used (Single-use enforcement)
                    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id")->execute([':id' => $resetId]);

                    // 3. Invalidate all other active reset tokens for this user
                    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")
                        ->execute([':uid' => $userId]);

                    $pdo->commit();

                    // 4. Send security confirmation email
                    EmailService::notifyPasswordResetSuccess($pdo, $userEmail, $userName, $userId);

                    // 5. In-App Notification
                    NotificationService::create(
                        $pdo,
                        $userId,
                        'account',
                        'Password Reset Successfully',
                        'Your account password was updated successfully. You can now log in using your new credentials.',
                        'tenantProfile',
                        $userId,
                        'password_reset'
                    );

                    // 6. Destroy reset session and flag completion
                    unset($_SESSION['forgot_pwd']);
                    $_SESSION['forgot_pwd_done'] = true;

                    $resp = [
                        'status'  => 'success',
                        'message' => 'Your password has been successfully reset!',
                        'screen'  => 4
                    ];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $resp = ['status' => 'error', 'message' => 'Unable to reset password. Please try again later.'];
                }
            }
        }

        if (isJsonRequest()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($resp);
            exit;
        }

        if (($resp['status'] ?? '') === 'success') {
            header('Location: forgot_password.php');
            exit;
        } else {
            $initError = $resp['message'] ?? 'Password reset failed.';
        }
    }

    // Default Fallback
    if (isJsonRequest()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
        exit;
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

  <!-- Typography: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
            mono: ['"Courier New"', 'Courier', 'monospace']
          },
          colors: {
            brand: {
              50: '#eef2ff',
              100: '#e0e7ff',
              200: '#c7d2fe',
              500: '#6366f1',
              600: '#4f46e5',
              700: '#4338ca'
            }
          }
        }
      }
    }
  </script>

  <!-- Claymorphism / Glassmorphism Stylesheet -->
  <link rel="stylesheet" href="assets/css/claymorphism.css">

  <style>
    /* Monospace Code Input Formatting */
    .code-input-spacing {
      font-family: 'Courier New', Courier, monospace;
      letter-spacing: 0.45em;
      text-align: center;
      font-size: 1.5rem;
      font-weight: 800;
    }

    /* Loading Spinner */
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .btn-spinner {
      display: inline-block;
      width: 1rem;
      height: 1rem;
      border: 2px solid rgba(255, 255, 255, 0.35);
      border-top-color: #ffffff;
      border-radius: 9999px;
      animation: spin 0.75s linear infinite;
      margin-right: 0.5rem;
      vertical-align: middle;
    }

    /* Screen Fade Transition */
    .screen-fade {
      animation: screenFadeIn 0.22s ease-out;
    }
    @keyframes screenFadeIn {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body class="font-sans antialiased bg-gradient-to-br from-indigo-50 via-purple-50 to-violet-100 min-h-screen flex items-center justify-center p-4 relative overflow-x-hidden selection:bg-indigo-500 selection:text-white">

  <!-- Decorative Background Orbs for Glassmorphism Depth -->
  <div class="absolute -top-24 -left-24 w-80 h-80 rounded-full bg-indigo-400/30 blur-3xl pointer-events-none"></div>
  <div class="absolute -bottom-24 -right-24 w-96 h-96 rounded-full bg-purple-400/30 blur-3xl pointer-events-none"></div>

  <!-- Main Card Container -->
  <div class="w-full max-w-md p-7 sm:p-9 rounded-3xl bg-white/85 backdrop-blur-xl border border-white/70 shadow-2xl relative z-10 transition-all duration-300">

    <!-- Brand Pill -->
    <div class="text-center mb-6">
      <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-indigo-50/90 border border-indigo-100 shadow-inner text-xs font-bold text-indigo-700">
        <img src="assets/images/tararent-logo.png" alt="TaraRent Logo" class="w-4 h-4 object-contain" style="width: 18px; height: 18px; object-fit: contain;">
        <span>TaraRent</span>
      </div>
    </div>

    <!-- =====================================================================
         SCREEN 1 – FORGOT PASSWORD (REGISTERED EMAIL)
         ===================================================================== -->
    <div id="screen1View" class="screen-fade">
      <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight text-center mb-2 flex items-center justify-center gap-2">
        <span>🔐</span>
        <span>Forgot Password</span>
      </h1>
      <p class="text-xs sm:text-sm text-slate-500 text-center leading-relaxed mb-6">
        Enter your registered email address to receive a 6-digit verification code to reset your account password.
      </p>

      <div id="screen1Alert" class="hidden p-3.5 rounded-xl text-xs sm:text-sm font-medium mb-5 flex items-start gap-2.5 bg-rose-50 border border-rose-200 text-rose-800">
        <span class="flex-shrink-0">⚠️</span>
        <span id="screen1AlertText"></span>
      </div>

      <form id="formScreen1" onsubmit="handleSendCode(event)" class="space-y-4">
        <div>
          <label for="screen1Email" class="block text-xs sm:text-sm font-bold text-slate-700 mb-1.5 flex items-center gap-1.5">
            <span>📧</span>
            <span>Registered Email Address</span>
          </label>
          <input 
            type="email" 
            id="screen1Email" 
            name="email" 
            class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all shadow-inner text-sm font-medium" 
            placeholder="e.g. name@example.com" 
            required 
            autofocus
          >
        </div>

        <button 
          type="submit" 
          id="btnSendCode" 
          class="w-full py-3.5 px-6 rounded-xl bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-bold text-sm shadow-lg shadow-indigo-500/25 active:scale-[0.99] transition-all flex items-center justify-center gap-2 cursor-pointer disabled:opacity-75 disabled:cursor-not-allowed"
        >
          <span>✉️ Send Verification Code</span>
        </button>
      </form>

      <div class="text-center mt-6 pt-2">
        <a href="login.php" class="text-xs sm:text-sm font-bold text-indigo-600 hover:text-indigo-800 transition-colors inline-flex items-center gap-1.5">
          <span>🏠</span>
          <span>Back to Login</span>
        </a>
      </div>
    </div>

    <!-- =====================================================================
         SCREEN 2 – VERIFY YOUR EMAIL (6-DIGIT CODE)
         ===================================================================== -->
    <div id="screen2View" class="screen-fade hidden">
      <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight text-center mb-2 flex items-center justify-center gap-2">
        <span>✉️</span>
        <span>Verify Your Email</span>
      </h1>
      <p class="text-xs sm:text-sm text-slate-500 text-center leading-relaxed mb-5">
        Enter the 6-digit verification code sent to your registered email address.
      </p>

      <!-- Registered Email Pill with Change Option -->
      <div class="bg-indigo-50/80 border border-indigo-100 rounded-xl px-3.5 py-2.5 mb-5 flex items-center justify-between gap-2 text-xs sm:text-sm text-indigo-900">
        <div class="flex items-center gap-2 overflow-hidden truncate">
          <span>📧</span>
          <span class="font-bold truncate" id="screen2EmailDisplay">email@example.com</span>
        </div>
        <button type="button" onclick="goToScreen(1)" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 underline flex-shrink-0 cursor-pointer">
          Change
        </button>
      </div>

      <div id="screen2Alert" class="hidden p-3.5 rounded-xl text-xs sm:text-sm font-medium mb-5 flex items-start gap-2.5 bg-rose-50 border border-rose-200 text-rose-800">
        <span class="flex-shrink-0">⚠️</span>
        <span id="screen2AlertText"></span>
      </div>

      <form id="formScreen2" onsubmit="handleVerifyCode(event)" class="space-y-4">
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label for="screen2Code" class="text-xs sm:text-sm font-bold text-slate-700 flex items-center gap-1.5">
              <span>🔢</span>
              <span>Enter Verification Code</span>
            </label>
            <span class="text-[11px] font-bold text-rose-600 bg-rose-50 px-2 py-0.5 rounded-full border border-rose-100">
              Valid for 5 mins
            </span>
          </div>
          <input 
            type="text" 
            id="screen2Code" 
            name="code" 
            class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all shadow-inner code-input-spacing" 
            placeholder="••••••" 
            maxlength="6" 
            pattern="[0-9]{6}" 
            inputmode="numeric" 
            autocomplete="one-time-code"
            required 
          >
        </div>

        <button 
          type="submit" 
          id="btnVerifyCode" 
          class="w-full py-3.5 px-6 rounded-xl bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-bold text-sm shadow-lg shadow-indigo-500/25 active:scale-[0.99] transition-all flex items-center justify-center gap-2 cursor-pointer disabled:opacity-75 disabled:cursor-not-allowed"
        >
          <span>✅ Verify Code</span>
        </button>
      </form>

      <div class="flex items-center justify-between mt-6 pt-2 text-xs sm:text-sm">
        <button 
          type="button" 
          id="btnResendCode" 
          onclick="handleResendCode()" 
          class="font-bold text-indigo-600 hover:text-indigo-800 transition-colors inline-flex items-center gap-1.5 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
        >
          <span>🔄</span>
          <span id="btnResendText">Resend Code</span>
        </button>
        <a href="login.php" class="font-bold text-slate-500 hover:text-slate-700 transition-colors inline-flex items-center gap-1.5">
          <span>🏠</span>
          <span>Back to Login</span>
        </a>
      </div>
    </div>

    <!-- =====================================================================
         SCREEN 3 – CHANGE PASSWORD
         Strictly gated by backend verification token
         ===================================================================== -->
    <div id="screen3View" class="screen-fade hidden">
      <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight text-center mb-2 flex items-center justify-center gap-2">
        <span>🔑</span>
        <span>Change Password</span>
      </h1>
      <p class="text-xs sm:text-sm text-slate-500 text-center leading-relaxed mb-6">
        Your email has been successfully verified. You can now create a new password.
      </p>

      <div id="screen3Alert" class="hidden p-3.5 rounded-xl text-xs sm:text-sm font-medium mb-5 flex items-start gap-2.5 bg-rose-50 border border-rose-200 text-rose-800">
        <span class="flex-shrink-0">⚠️</span>
        <span id="screen3AlertText"></span>
      </div>

      <form id="formScreen3" onsubmit="handleResetPassword(event)" class="space-y-4">
        <input type="hidden" id="screen3ResetToken" name="reset_token" value="">

        <div>
          <label for="screen3Password" class="block text-xs sm:text-sm font-bold text-slate-700 mb-1.5 flex items-center gap-1.5">
            <span>🔑</span>
            <span>New Password</span>
          </label>
          <div class="relative">
            <input 
              type="password" 
              id="screen3Password" 
              name="new_password" 
              class="w-full px-4 py-3 pr-11 rounded-xl bg-slate-50 border border-slate-200 text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all shadow-inner text-sm font-medium" 
              placeholder="Enter at least 6 characters" 
              required 
              minlength="6"
              autofocus
            >
            <button 
              type="button" 
              class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 text-sm cursor-pointer" 
              onclick="togglePasswordVisibility('screen3Password', this)" 
              title="Show / Hide Password"
            >
              👁️
            </button>
          </div>
        </div>

        <div>
          <label for="screen3ConfirmPassword" class="block text-xs sm:text-sm font-bold text-slate-700 mb-1.5 flex items-center gap-1.5">
            <span>🔒</span>
            <span>Confirm Password</span>
          </label>
          <div class="relative">
            <input 
              type="password" 
              id="screen3ConfirmPassword" 
              name="confirm_password" 
              class="w-full px-4 py-3 pr-11 rounded-xl bg-slate-50 border border-slate-200 text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all shadow-inner text-sm font-medium" 
              placeholder="Confirm your new password" 
              required 
              minlength="6"
            >
            <button 
              type="button" 
              class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 text-sm cursor-pointer" 
              onclick="togglePasswordVisibility('screen3ConfirmPassword', this)" 
              title="Show / Hide Password"
            >
              👁️
            </button>
          </div>
        </div>

        <button 
          type="submit" 
          id="btnResetPassword" 
          class="w-full py-3.5 px-6 rounded-xl bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-bold text-sm shadow-lg shadow-indigo-500/25 active:scale-[0.99] transition-all flex items-center justify-center gap-2 cursor-pointer disabled:opacity-75 disabled:cursor-not-allowed"
        >
          <span>💾 Reset Password</span>
        </button>
      </form>

      <div class="text-center mt-6 pt-2">
        <a href="login.php" class="text-xs sm:text-sm font-bold text-slate-500 hover:text-slate-700 transition-colors inline-flex items-center gap-1.5">
          <span>🏠</span>
          <span>Back to Login</span>
        </a>
      </div>
    </div>

    <!-- =====================================================================
         SCREEN 4 – PASSWORD RESET SUCCESSFUL
         ===================================================================== -->
    <div id="screen4View" class="screen-fade hidden text-center py-2">
      <!-- Animated Success Checkmark Badge -->
      <div class="w-20 h-20 mx-auto mb-5 rounded-full bg-gradient-to-tr from-emerald-400 to-emerald-500 flex items-center justify-center shadow-xl shadow-emerald-500/30 text-3xl">
        <span>✅</span>
      </div>

      <h1 class="text-xl sm:text-2xl font-extrabold text-emerald-900 tracking-tight mb-2">
        Password Reset Successful
      </h1>
      <p class="text-sm font-bold text-emerald-700 mb-1">
        Your password has been successfully reset!
      </p>
      <p class="text-xs sm:text-sm text-slate-500 leading-relaxed mb-7 max-w-xs mx-auto">
        You can now log in using your new password.
      </p>

      <a 
        href="login.php" 
        class="w-full py-3.5 px-6 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-white font-bold text-sm shadow-lg shadow-emerald-500/25 active:scale-[0.99] transition-all inline-flex items-center justify-center gap-2 text-center"
      >
        <span>🏠 Back to Login</span>
      </a>
    </div>

  </div>

  <script>
    // State management
    let currentScreen = 1;
    let registeredEmail = '';
    let verifiedResetToken = '';
    let resendCooldownTimer = null;
    let resendSecondsRemaining = 0;

    // =========================================================================
    // NAVIGATION & REFRESH ENFORCEMENT: RETURN TO LOGIN ON REFRESH OR BACK
    // =========================================================================

    // 1. Detect page refresh / reload via Navigation Timing API
    (function checkPageReload() {
      let isReload = false;
      if (window.performance) {
        const navEntries = performance.getEntriesByType && performance.getEntriesByType('navigation');
        if (navEntries && navEntries.length > 0) {
          isReload = navEntries[0].type === 'reload';
        } else if (performance.navigation) {
          isReload = performance.navigation.type === 1;
        }
      }

      if (isReload) {
        // If refreshed, immediately abandon any in-flight reset session and navigate to login.php
        window.location.replace('forgot_password.php?abandon=1');
      }
    })();

    // 2. Setup History State & Popstate Listener (Intercept Browser Back Button)
    window.history.replaceState({ screen: 1 }, '', window.location.href);

    window.addEventListener('popstate', function (event) {
      // Whenever the user clicks the browser Back button at ANY point in the process:
      // Invalidate the session on the backend and return to login.php
      if (navigator.sendBeacon) {
        navigator.sendBeacon('forgot_password.php', JSON.stringify({ action: 'abandon' }));
      }
      window.location.replace('forgot_password.php?abandon=1');
    });

    // 3. Pagehide / BeforeUnload Listener (Abandonment cleanup on tab close)
    window.addEventListener('pagehide', function () {
      if (currentScreen > 1 && currentScreen < 4 && registeredEmail) {
        if (navigator.sendBeacon) {
          navigator.sendBeacon('forgot_password.php', JSON.stringify({ action: 'abandon' }));
        }
      }
    });

    // =========================================================================
    // SCREEN DISPLAY & TOGGLES
    // =========================================================================
    function goToScreen(screenNum) {
      currentScreen = screenNum;

      // Update Screen Visibility
      document.getElementById('screen1View').classList.toggle('hidden', screenNum !== 1);
      document.getElementById('screen2View').classList.toggle('hidden', screenNum !== 2);
      document.getElementById('screen3View').classList.toggle('hidden', screenNum !== 3);
      document.getElementById('screen4View').classList.toggle('hidden', screenNum !== 4);

      // Push history state so back button press can be captured by popstate
      window.history.pushState({ screen: screenNum }, '', window.location.href);

      // Focus fields
      if (screenNum === 1) {
        setTimeout(() => document.getElementById('screen1Email').focus(), 150);
      } else if (screenNum === 2) {
        document.getElementById('screen2EmailDisplay').textContent = registeredEmail || 'your email';
        setTimeout(() => document.getElementById('screen2Code').focus(), 150);
      } else if (screenNum === 3) {
        setTimeout(() => document.getElementById('screen3Password').focus(), 150);
      }
    }

    function togglePasswordVisibility(fieldId, btn) {
      const input = document.getElementById(fieldId);
      if (!input) return;
      if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈';
        btn.title = 'Hide Password';
      } else {
        input.type = 'password';
        btn.textContent = '👁️';
        btn.title = 'Show Password';
      }
    }

    function showScreenAlert(screenNum, msg, type = 'error') {
      const alertBox = document.getElementById(`screen${screenNum}Alert`);
      const alertText = document.getElementById(`screen${screenNum}AlertText`);
      if (!alertBox || !alertText) return;

      alertBox.className = `p-3.5 rounded-xl text-xs sm:text-sm font-medium mb-5 flex items-start gap-2.5 border ${
        type === 'success' 
          ? 'bg-emerald-50 border-emerald-200 text-emerald-800' 
          : 'bg-rose-50 border-rose-200 text-rose-800'
      }`;
      alertText.textContent = msg;
      alertBox.classList.remove('hidden');
    }

    function hideScreenAlert(screenNum) {
      const alertBox = document.getElementById(`screen${screenNum}Alert`);
      if (alertBox) alertBox.classList.add('hidden');
    }

    // =========================================================================
    // SCREEN 1: SEND VERIFICATION CODE
    // =========================================================================
    async function handleSendCode(e) {
      e.preventDefault();
      hideScreenAlert(1);

      const emailInput = document.getElementById('screen1Email');
      const email = emailInput.value.trim();
      if (!email) {
        showScreenAlert(1, 'Please enter your registered email address.');
        return;
      }

      const btn = document.getElementById('btnSendCode');
      const originalBtnHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="btn-spinner"></span> Sending Verification Code...';

      try {
        const res = await fetch('forgot_password.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'send_code',
            email: email
          })
        });

        const data = await res.json();

        if (res.ok && data.status === 'success') {
          registeredEmail = data.email || email;
          startResendCooldown(45);
          goToScreen(2);
        } else {
          showScreenAlert(1, data.message || 'Unable to send verification code. Please check your email and try again.');
        }
      } catch (err) {
        console.error('[Send Code Error]:', err);
        showScreenAlert(1, 'Network or server error while sending verification code. Please check your connection.');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
      }
    }

    // =========================================================================
    // SCREEN 2: VERIFY CODE
    // =========================================================================
    async function handleVerifyCode(e) {
      e.preventDefault();
      hideScreenAlert(2);

      const codeInput = document.getElementById('screen2Code');
      const code = codeInput.value.trim();

      if (!code || !/^[0-9]{6}$/.test(code)) {
        showScreenAlert(2, 'Please enter the full 6-digit numeric verification code.');
        codeInput.focus();
        return;
      }

      const btn = document.getElementById('btnVerifyCode');
      const originalBtnHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="btn-spinner"></span> Verifying Code...';

      try {
        const res = await fetch('forgot_password.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'verify_code',
            email: registeredEmail,
            code: code
          })
        });

        const data = await res.json();

        if (res.ok && data.status === 'success') {
          // Verification confirmed by PHP backend! Proceed to Screen 3
          verifiedResetToken = data.reset_token || '';
          document.getElementById('screen3ResetToken').value = verifiedResetToken;
          goToScreen(3);
        } else {
          // Failure: remain on Screen 2 and display error
          showScreenAlert(2, data.message || 'Invalid verification code. Please check your email and try again.');
          codeInput.select();
        }
      } catch (err) {
        console.error('[Verify Code Error]:', err);
        showScreenAlert(2, 'Network or server error verifying code. Please try again.');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
      }
    }

    // Resend Code Action on Screen 2
    async function handleResendCode() {
      if (resendSecondsRemaining > 0) return;
      hideScreenAlert(2);

      const btn = document.getElementById('btnResendCode');
      const textEl = document.getElementById('btnResendText');
      btn.disabled = true;
      textEl.textContent = 'Sending...';

      try {
        const res = await fetch('forgot_password.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'resend_code',
            email: registeredEmail
          })
        });

        const data = await res.json();

        if (res.ok && data.status === 'success') {
          showScreenAlert(2, data.message || 'A fresh verification code has been dispatched to your email.', 'success');
          startResendCooldown(45);
          document.getElementById('screen2Code').value = '';
          document.getElementById('screen2Code').focus();
        } else {
          showScreenAlert(2, data.message || 'Unable to resend code at this time.');
          if (data.cooldown_remaining) {
            startResendCooldown(data.cooldown_remaining);
          } else {
            btn.disabled = false;
            textEl.textContent = 'Resend Code';
          }
        }
      } catch (err) {
        console.error('[Resend Error]:', err);
        showScreenAlert(2, 'Network error requesting new code.');
        btn.disabled = false;
        textEl.textContent = 'Resend Code';
      }
    }

    function startResendCooldown(seconds) {
      if (resendCooldownTimer) clearInterval(resendCooldownTimer);
      resendSecondsRemaining = seconds;

      const btn = document.getElementById('btnResendCode');
      const textEl = document.getElementById('btnResendText');
      btn.disabled = true;

      const updateText = () => {
        if (resendSecondsRemaining > 0) {
          textEl.textContent = `Resend in ${resendSecondsRemaining}s`;
          resendSecondsRemaining--;
        } else {
          clearInterval(resendCooldownTimer);
          btn.disabled = false;
          textEl.textContent = 'Resend Code';
        }
      };

      updateText();
      resendCooldownTimer = setInterval(updateText, 1000);
    }

    // =========================================================================
    // SCREEN 3: RESET PASSWORD
    // =========================================================================
    async function handleResetPassword(e) {
      e.preventDefault();
      hideScreenAlert(3);

      const p1 = document.getElementById('screen3Password').value;
      const p2 = document.getElementById('screen3ConfirmPassword').value;

      if (!p1 || p1.length < 6) {
        showScreenAlert(3, 'New password must be at least 6 characters long.');
        document.getElementById('screen3Password').focus();
        return;
      }

      if (p1 !== p2) {
        showScreenAlert(3, 'The new passwords do not match. Please verify and re-type.');
        document.getElementById('screen3ConfirmPassword').focus();
        return;
      }

      const tokenVal = document.getElementById('screen3ResetToken').value || verifiedResetToken;
      const btn = document.getElementById('btnResetPassword');
      const originalBtnHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="btn-spinner"></span> Resetting Password...';

      try {
        const res = await fetch('forgot_password.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'reset_password',
            new_password: p1,
            confirm_password: p2,
            reset_token: tokenVal
          })
        });

        const data = await res.json();

        if (res.ok && data.status === 'success') {
          // Success! Move to Screen 4
          goToScreen(4);
        } else {
          // If server reports unauthorized / session timeout, redirect to login or corresponding screen
          if (data.screen && data.screen !== 3) {
            goToScreen(data.screen);
            showScreenAlert(data.screen, data.message || 'Session expired. Please start over.');
          } else {
            showScreenAlert(3, data.message || 'Unable to update password. Please try again.');
          }
        }
      } catch (err) {
        console.error('[Reset Password Error]:', err);
        showScreenAlert(3, 'Network or server error updating password. Please try again.');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
      }
    }
  </script>

</body>
</html>
