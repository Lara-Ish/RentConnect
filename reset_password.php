<?php
/**
 * RentConnect - Secure Password Reset Interface
 * Supports:
 *   1. Six-Digit Verification Code validation (Forgot Password flow with 5-minute expiry)
 *   2. Cryptographically secure URL token validation (Account Activation flow)
 * Validates SHA-256 hashed single-use codes, enforces bcrypt hashing, and updates user credentials.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/email_service.php';
require_once __DIR__ . '/includes/notification_service.php';

// Redirect if already logged in
if (Auth::check()) {
    if (Auth::isLandlord()) {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: tenant_portal.php');
    }
    exit;
}

$pdo = Database::getConnection();

$token = trim((string)($_REQUEST['token'] ?? ''));
$emailParam = trim((string)($_REQUEST['email'] ?? ''));
$codeSent = !empty($_GET['code_sent']);

$errorMessage = null;
$successMessage = null;
$tokenMode = !empty($token);
$tokenValid = false;
$resetRecord = null;

// If no token is provided, forward to the 4-step Forgot Password workflow
if (!$tokenMode) {
    $redirectUrl = 'forgot_password.php';
    if (!empty($emailParam)) {
        $redirectUrl .= '?email=' . urlencode($emailParam);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// =============================================================================
// MODE 1: TOKEN-BASED RESET (Account Activation / Direct Link)
// =============================================================================
if ($tokenMode) {
    if (strlen($token) < 32) {
        $errorMessage = 'The password reset token is missing or malformed.';
    } else {
        $tokenHash = hash('sha256', $token);

        $lookupStmt = $pdo->prepare("
            SELECT pr.id, pr.user_id, pr.email, pr.expires_at, pr.used_at,
                   u.name, u.username, u.role
            FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.token_hash = :thash
            LIMIT 1
        ");
        $lookupStmt->execute([':thash' => $tokenHash]);
        $resetRecord = $lookupStmt->fetch(PDO::FETCH_ASSOC);

        if (!$resetRecord) {
            $errorMessage = 'This password setup link is invalid or does not exist.';
        } elseif (!empty($resetRecord['used_at'])) {
            $errorMessage = 'This password setup link has already been used.';
        } elseif (strtotime($resetRecord['expires_at']) < time()) {
            $errorMessage = 'This password setup link has expired. Please request a new link.';
        } else {
            $tokenValid = true;
        }
    }

    if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (empty($newPassword) || strlen($newPassword) < 6) {
            $errorMessage = 'Password must be at least 6 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = 'The two passwords do not match. Please try again.';
        } else {
            try {
                $pdo->beginTransaction();

                $userId = (int)$resetRecord['user_id'];
                $resetId = (int)$resetRecord['id'];
                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

                $updateUser = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
                $updateUser->execute([':hash' => $newHash, ':id' => $userId]);

                $markUsed = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id");
                $markUsed->execute([':id' => $resetId]);

                $invalidateOther = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL");
                $invalidateOther->execute([':uid' => $userId]);

                $pdo->commit();

                // Send confirmation email
                EmailService::notifyPasswordResetSuccess($pdo, $resetRecord['email'], $resetRecord['name'], $userId);

                // In-App Notification
                NotificationService::create(
                    $pdo,
                    $userId,
                    $resetRecord['role'],
                    'account',
                    'Password Set Successfully',
                    'Your account password was updated successfully. You can now log in securely.',
                    'sectionProfile',
                    $userId,
                    'password_reset'
                );

                $successMessage = 'Your password has been successfully configured! You can now log in using your new credentials.';
                $tokenValid = false;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMessage = 'Unable to update password. Please try again later.';
            }
        }
    }
}

// =============================================================================
// MODE 2: SIX-DIGIT VERIFICATION CODE RESET (Forgot Password Flow)
// =============================================================================
if (!$tokenMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $emailParam = $email;

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please provide a valid registered email address.';
    } elseif (empty($code) || !preg_match('/^[0-9]{6}$/', $code)) {
        $errorMessage = 'Please enter the 6-digit numeric verification code sent to your email.';
    } elseif (empty($newPassword) || strlen($newPassword) < 6) {
        $errorMessage = 'New password must be at least 6 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $errorMessage = 'The new passwords do not match. Please verify and re-type.';
    } else {
        // Find latest active code for this email
        $stmt = $pdo->prepare("
            SELECT pr.id, pr.user_id, pr.email, pr.token_hash, pr.expires_at, pr.used_at, 
                   pr.attempts, pr.max_attempts, u.name, u.username, u.role
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
            $errorMessage = 'No active verification code found for this email. Please request a new code.';
        } elseif ((int)$record['attempts'] >= (int)$record['max_attempts']) {
            $errorMessage = 'Too many failed verification attempts. For your security, this code is no longer valid. Please request a new code.';
        } elseif (strtotime($record['expires_at']) < time()) {
            $errorMessage = 'This verification code has expired. Codes are only valid for 5 minutes. Please request a new code.';
        } else {
            $submittedHash = hash('sha256', $code);

            if (!hash_equals($record['token_hash'], $submittedHash)) {
                // Increment failed attempt counter
                $pdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = :id")
                    ->execute([':id' => $record['id']]);
                
                $remaining = max(0, (int)$record['max_attempts'] - ((int)$record['attempts'] + 1));
                $errorMessage = "Invalid verification code. Please check your email. ({$remaining} attempts remaining)";
            } else {
                // Verification successful! Update password
                try {
                    $pdo->beginTransaction();

                    $userId = (int)$record['user_id'];
                    $resetId = (int)$record['id'];
                    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

                    // Update user credentials
                    $updateUser = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
                    $updateUser->execute([':hash' => $newHash, ':id' => $userId]);

                    // Invalidate the used verification code
                    $markUsed = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id");
                    $markUsed->execute([':id' => $resetId]);

                    // Invalidate other active codes for user
                    $invalidateOther = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL");
                    $invalidateOther->execute([':uid' => $userId]);

                    $pdo->commit();

                    // Send security confirmation email
                    EmailService::notifyPasswordResetSuccess($pdo, $record['email'], $record['name'], $userId);

                    // Create in-app notification
                    NotificationService::create(
                        $pdo,
                        $userId,
                        $record['role'],
                        'account',
                        'Password Reset Successfully',
                        'Your account password was updated successfully. If this wasn\'t you, contact management immediately.',
                        'sectionProfile',
                        $userId,
                        'password_reset'
                    );

                    $successMessage = 'Your password has been successfully reset! You can now log in using your new credentials.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errorMessage = 'Unable to reset password. Please try again later.';
                }
            }
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
      max-width: 460px;
      padding: 44px 38px;
    }

    .login-heading {
      font-size: 28px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -0.6px;
      margin-bottom: 8px;
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
      margin-bottom: 20px;
    }

    .code-input-field {
      font-family: 'Courier New', Courier, monospace;
      font-size: 22px;
      font-weight: 800;
      letter-spacing: 6px;
      text-align: center;
    }
  </style>
</head>
<body>

  <div class="login-wrapper">
    <div class="clay-card login-clay-card">

      <!-- Brand Pill -->
      <div style="text-align: center;">
        <div class="app-badge-pill">
          <img src="assets/images/tararent-logo.png" alt="TaraRent Logo" style="width: 18px; height: 18px; object-fit: contain;">
          <span>TaraRent</span>
        </div>
      </div>

      <!-- Main Heading -->
      <h1 class="login-heading"><?= $tokenMode ? 'Set New Password' : 'Reset Password' ?></h1>

      <!-- Success State -->
      <?php if ($successMessage): ?>
        <div class="alert-clay alert-clay-success" style="margin-bottom: 24px;">
          <div class="alert-body-clay">
            <h4 style="color: #065f46;">Success</h4>
            <p style="font-size: 13px; line-height: 1.5;"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>

        <div style="text-align: center; margin-top: 20px;">
          <a href="login.php" class="clay-btn clay-btn-primary clay-btn-full" style="text-decoration: none; display: inline-block;">
            <span>Proceed to Log in</span>
          </a>
        </div>

      <!-- Mode 1: Token-Based Reset Form -->
      <?php elseif ($tokenMode): ?>
        <?php if (!$tokenValid): ?>
          <div class="alert-clay alert-clay-warning" style="margin-bottom: 24px;">
            <div class="alert-icon-clay">⚠️</div>
            <div class="alert-body-clay">
              <h4 style="color: #9f1239;">Link Unavailable</h4>
              <p style="font-size: 13px; line-height: 1.5;"><?= htmlspecialchars($errorMessage ?? 'Invalid password setup link.', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          </div>

          <div style="display: flex; flex-direction: column; gap: 12px; text-align: center;">
            <a href="forgot_password.php" class="clay-btn clay-btn-primary clay-btn-full" style="text-decoration: none; display: inline-block;">
              <span>Request Password Reset</span>
            </a>
            <a href="login.php" style="font-size: 13px; font-weight: 700; color: var(--clay-primary-dark); text-decoration: none; margin-top: 8px;">
              ← Return to Log in
            </a>
          </div>
        <?php else: ?>
          <p style="font-size: 13.5px; color: var(--text-muted); text-align: center; line-height: 1.5; margin: 0 0 24px 0;">
            Create a secure password for account <strong><?= htmlspecialchars($resetRecord['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong> (<?= htmlspecialchars($resetRecord['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>).
          </p>

          <?php if ($errorMessage): ?>
            <div class="alert-clay alert-clay-warning" style="margin-bottom: 20px;">
              <div class="alert-icon-clay">⚠️</div>
              <div class="alert-body-clay">
                <h4 style="color: #9f1239;">Error</h4>
                <p style="font-size: 13px; line-height: 1.45;"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
              </div>
            </div>
          <?php endif; ?>

          <form action="reset_password.php" method="POST">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
              <label for="new_password" class="form-label">New Password</label>
              <input 
                type="password" 
                id="new_password" 
                name="new_password" 
                class="clay-input" 
                placeholder="Enter at least 6 characters" 
                required 
                minlength="6"
                autofocus
              >
            </div>

            <div class="form-group">
              <label for="confirm_password" class="form-label">Confirm New Password</label>
              <input 
                type="password" 
                id="confirm_password" 
                name="confirm_password" 
                class="clay-input" 
                placeholder="Confirm your new password" 
                required 
                minlength="6"
              >
            </div>

            <button type="submit" class="clay-btn clay-btn-primary clay-btn-full" style="margin-top: 10px;">
              <span>Set Password &amp; Activate</span>
            </button>
          </form>

          <div style="text-align: center; margin-top: 24px;">
            <a href="login.php" style="font-size: 13px; font-weight: 700; color: var(--clay-primary-dark); text-decoration: none;">
              ← Return to Log in
            </a>
          </div>
        <?php endif; ?>

      <!-- Mode 2: 6-Digit Verification Code Reset Form -->
      <?php else: ?>
        <p style="font-size: 13.5px; color: var(--text-muted); text-align: center; line-height: 1.5; margin: 0 0 20px 0;">
          Enter the 6-digit verification code sent to your email and your new password.
        </p>

        <?php if ($codeSent): ?>
          <div class="alert-clay alert-clay-success" style="margin-bottom: 20px;">
            <div class="alert-icon-clay">✉️</div>
            <div class="alert-body-clay">
              <h4 style="color: #065f46;">Verification Code Sent</h4>
              <p style="font-size: 13px; line-height: 1.45;">
                We sent a 6-digit code to your email. It is valid for <strong>5 minutes</strong>.
              </p>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
          <div class="alert-clay alert-clay-warning" style="margin-bottom: 20px;">
            <div class="alert-icon-clay">⚠️</div>
            <div class="alert-body-clay">
              <h4 style="color: #9f1239;">Notice</h4>
              <p style="font-size: 13px; line-height: 1.45;"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          </div>
        <?php endif; ?>

        <form action="reset_password.php" method="POST">
          <div class="form-group">
            <label for="email" class="form-label">Registered Email</label>
            <input 
              type="email" 
              id="email" 
              name="email" 
              class="clay-input" 
              placeholder="e.g. name@example.com" 
              value="<?= htmlspecialchars($emailParam, ENT_QUOTES, 'UTF-8') ?>" 
              required
            >
          </div>

          <div class="form-group">
            <div style="display: flex; justify-content: space-between; align-items: baseline;">
              <label for="code" class="form-label">6-Digit Verification Code</label>
              <span style="font-size: 11.5px; font-weight: 600; color: #dc2626;">Valid 5 mins</span>
            </div>
            <input 
              type="text" 
              id="code" 
              name="code" 
              class="clay-input code-input-field" 
              placeholder="••••••" 
              maxlength="6" 
              pattern="[0-9]{6}"
              inputmode="numeric"
              autocomplete="one-time-code"
              required 
              autofocus
            >
          </div>

          <div class="form-group">
            <label for="new_password" class="form-label">New Password</label>
            <input 
              type="password" 
              id="new_password" 
              name="new_password" 
              class="clay-input" 
              placeholder="At least 6 characters" 
              required 
              minlength="6"
            >
          </div>

          <div class="form-group">
            <label for="confirm_password" class="form-label">Confirm New Password</label>
            <input 
              type="password" 
              id="confirm_password" 
              name="confirm_password" 
              class="clay-input" 
              placeholder="Re-type new password" 
              required 
              minlength="6"
            >
          </div>

          <button type="submit" class="clay-btn clay-btn-primary clay-btn-full" style="margin-top: 10px;">
            <span>Verify Code &amp; Reset Password</span>
          </button>
        </form>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; font-size: 13px;">
          <a href="forgot_password.php" style="font-weight: 600; color: var(--clay-primary-dark); text-decoration: none;">
            ← Request New Code
          </a>
          <a href="login.php" style="font-weight: 600; color: var(--text-muted); text-decoration: none;">
            Return to Log in
          </a>
        </div>

      <?php endif; ?>

    </div>
  </div>

</body>
</html>
