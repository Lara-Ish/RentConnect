<?php
/**
 * RentConnect - Tenant Resident Portal
 * Multi-Tenant Architecture with Retractable Claymorphic Sidebar Navigation
 * Currency: ₱
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
Auth::requireTenant();

$user = Auth::user();
$pdo = Database::getConnection();

// Fetch fresh tenant user details (including verification status)
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$userStmt->execute([':id' => $user['id']]);
$freshUser = $userStmt->fetch();
if ($freshUser) {
    $user = $freshUser;
}

// Resolve user avatar image URL dynamically from database record or uploaded face
$userAvatarUrl = '';
if (!empty($user['live_photo_filename'])) {
    if (file_exists(__DIR__ . '/uploads/id_verifications/' . $user['live_photo_filename'])) {
        $userAvatarUrl = 'uploads/id_verifications/' . htmlspecialchars($user['live_photo_filename']);
    } elseif (file_exists(__DIR__ . '/uploads/live_captures/' . $user['live_photo_filename'])) {
        $userAvatarUrl = 'uploads/live_captures/' . htmlspecialchars($user['live_photo_filename']);
    } elseif (file_exists(__DIR__ . '/uploads/' . $user['live_photo_filename'])) {
        $userAvatarUrl = 'uploads/' . htmlspecialchars($user['live_photo_filename']);
    }
}

// Extract first letter of user's first name dynamically for profile icon
$userFirstName = trim(explode(' ', trim($user['name'] ?? 'U'))[0]);
$userInitial = strtoupper(mb_substr($userFirstName, 0, 1));
if ($userInitial === '') {
    $userInitial = 'U';
}

// Fetch assigned unit details
$unitStmt = $pdo->prepare("SELECT * FROM units WHERE id = :uid LIMIT 1");
$unitStmt->execute([':uid' => $user['unit_id'] ?? 1]);
$unit = $unitStmt->fetch() ?: [
    'unit_number' => 'Unit 1',
    'monthly_rent' => 6000.00
];

// Fetch tickets submitted by this tenant
$ticketsStmt = $pdo->prepare("SELECT * FROM maintenance_tickets WHERE tenant_id = :tid ORDER BY created_at DESC");
$ticketsStmt->execute([':tid' => $user['id']]);
$myTickets = $ticketsStmt->fetchAll();

// Fetch payments for this tenant
$paymentsStmt = $pdo->prepare("SELECT * FROM payments WHERE tenant_id = :tid ORDER BY billing_cycle DESC");
$paymentsStmt->execute([':tid' => $user['id']]);
$myPayments = $paymentsStmt->fetchAll();
$currentPayment = !empty($myPayments) ? $myPayments[0] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resident Portal • RentConnect</title>

  <!-- Global Favicon -->
  <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32.png">
  <link rel="icon" type="image/png" sizes="64x64" href="assets/images/favicon-64.png">
  <link rel="apple-touch-icon" sizes="192x192" href="assets/images/favicon-192.png">
  <link rel="shortcut icon" href="favicon.ico">

  <!-- Typography -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Claymorphism Design System -->
  <link rel="stylesheet" href="assets/css/claymorphism.css">

  <!-- Dedicated OCR Library (Tesseract.js) -->
  <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

  <!-- face-api.js for 68-Point Facial Landmark Tracking (Local with CDN fallback) -->
  <script src="assets/js/face-api.min.js"></script>
  <script>
    if (typeof faceapi === 'undefined') {
      document.write('<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.min.js"><\/script>');
    }
  </script>

  <style>
    .modal-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      z-index: 10000;
      align-items: center;
      justify-content: center;
      padding: 20px;
      pointer-events: none;
    }
    .modal-backdrop.open {
      display: flex !important;
      pointer-events: auto;
    }
    .clay-modal {
      width: 100%;
      max-width: 540px;
      background: var(--clay-card-bg);
      border-radius: var(--radius-xl);
      box-shadow: var(--clay-card-shadow-lg);
      border: 1px solid var(--clay-border);
      padding: 34px;
      animation: modalSlide 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
      max-height: 90vh;
      overflow-y: auto;
      pointer-events: auto;
    }
    @keyframes modalSlide {
      from { transform: translateY(20px) scale(0.96); opacity: 0; }
      to { transform: translateY(0) scale(1); opacity: 1; }
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
      background: rgba(15, 23, 42, 0.88);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      color: #ffffff;
      padding: 7px 14px;
      border-radius: 999px;
      font-size: 11.5px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
      transition: all 0.2s ease;
      z-index: 10;
    }

    .face-status-pill.status-liveness,
    .face-status-pill.challenge-active {
      background: rgba(14, 165, 233, 0.92);
      color: #f0f9ff;
    }

    .face-status-pill.status-turned {
      background: rgba(168, 85, 247, 0.92);
      color: #faf5ff;
    }

    .face-status-pill.status-holding,
    .face-status-pill.perfect-capturing {
      background: rgba(5, 150, 105, 0.92);
      color: #ecfdf5;
    }

    .face-status-pill.status-spoof,
    .face-status-pill.multi-face {
      background: rgba(225, 29, 72, 0.95);
      color: #fff1f2;
    }

    .face-status-pill.needs-closer,
    .face-status-pill.needs-center {
      background: rgba(217, 119, 6, 0.92);
      color: #fffbeb;
    }

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
      box-shadow: 0 4px 16px rgba(225, 29, 72, 0.4);
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
    }

    .face-hold-progress-fill {
      width: 0%;
      height: 100%;
      background: linear-gradient(90deg, #10b981, #059669);
      border-radius: 999px;
      transition: width 0.1s linear;
    }

    /* Inactive overlays must never block sidebar, settings, or dashboard buttons */
    .sidebar-overlay,
    .sidebar-overlay:not(.active) {
      display: none !important;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(3px);
      z-index: 998;
      pointer-events: none !important;
    }
    .sidebar-overlay.active {
      display: block !important;
      pointer-events: auto !important;
    }

    .modal-backdrop,
    .modal-backdrop:not(.open) {
      display: none !important;
      pointer-events: none !important;
    }
    .modal-backdrop.open {
      display: flex !important;
      pointer-events: auto !important;
    }

    /* Prevent clipping and ensure parent containers never cut off button hit areas or popovers */
    .clay-sidebar,
    #appSidebar,
    .sidebar-top-header,
    .sidebar-footer,
    .sidebar-settings-container,
    .header-settings-container {
      overflow: visible !important;
    }

    .clay-sidebar {
      position: sticky;
      top: 24px;
      z-index: 900;
    }

    /* Force Sidebar Toggle Button & Settings Button to Top Layer with High Z-Index */
    #sidebarToggleBtn,
    .sidebar-inner-toggle,
    .clay-toggle-btn {
      position: relative !important;
      z-index: 999 !important;
      pointer-events: auto !important;
      cursor: pointer !important;
    }

    #settingsToggleBtn,
    .sidebar-settings-container > button,
    .sidebar-settings-container .clay-btn,
    .header-settings-container > button,
    .header-settings-container .clay-btn {
      position: relative !important;
      z-index: 999 !important;
      pointer-events: auto !important;
      cursor: pointer !important;
    }

    /* Dropdown sits above buttons and remains interactive */
    .clay-settings-dropdown {
      z-index: 1050 !important;
      pointer-events: auto !important;
    }

    .settings-dropdown-item {
      position: relative;
      z-index: 1051 !important;
      pointer-events: auto !important;
      cursor: pointer !important;
    }

    /* Retracted mobile sidebar passes clicks through while toggle button peeks out */
    @media (max-width: 960px) {
      .clay-sidebar {
        z-index: 1000;
      }
      .clay-sidebar:not(.mobile-open) {
        pointer-events: none;
      }
      .clay-sidebar:not(.mobile-open) .sidebar-inner-toggle {
        position: absolute;
        right: -48px;
        top: 14px;
        background: var(--clay-card-bg);
        box-shadow: 4px 4px 12px rgba(166, 180, 204, 0.4);
        z-index: 1001 !important;
        pointer-events: auto !important;
        cursor: pointer !important;
      }
      .clay-sidebar.mobile-open {
        pointer-events: auto;
      }
      .clay-sidebar.mobile-open .sidebar-inner-toggle {
        position: relative;
        right: auto;
        top: auto;
        z-index: 1001 !important;
        pointer-events: auto !important;
        cursor: pointer !important;
      }
    }

    /* Correct Z-Index Stacking: Main content buttons & clickable dashboard elements forced to top layer */
    .clay-btn,
    .priority-pill,
    .sidebar-nav-item,
    .profile-subnav-item,
    .btn-remove-file,
    .btn-inspect-proof,
    .main-content button,
    .main-content .clay-btn,
    .dashboard-header-bar button,
    .verification-dashboard-warning button,
    .clay-dropzone,
    .drop-zone {
      position: relative;
      z-index: 50;
    }
  </style>
</head>
<body>

  <!-- Overlay for Mobile Retractable Sidebar -->
  <div class="sidebar-overlay" onclick="closeSidebarMobile()"></div>

  <!-- Full App Shell with Retractable Claymorphic Sidebar -->
  <div class="app-shell">
    
    <!-- ===================================================================
         TENANT RETRACTABLE CLAYMORPHIC SIDEBAR
         (Clean navigation drawer: No duplicate toggle button in sidebar)
         =================================================================== -->
    <aside class="clay-sidebar" id="appSidebar">
      <div>
        <!-- Brand Badge & Inner Sidebar Toggle (Strictly inside sidebar) -->
        <div class="sidebar-top-header">
          <div class="brand-wrapper" style="padding: 0; margin-bottom: 0;">
            <div class="brand-icon-clay" style="width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; background: #ffffff;">
              <img src="assets/images/favicon-64.png" alt="RentConnect Logo" style="width: 28px; height: 28px; object-fit: contain;">
            </div>
            <div class="hide-on-retract">
              <div style="font-size: 18px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px;">RentConnect</div>
              <div style="font-size: 10.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Resident Portal</div>
            </div>
          </div>
          <button type="button" id="sidebarToggleBtn" class="clay-toggle-btn sidebar-inner-toggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar" title="Toggle Sidebar">
            ☰
          </button>
        </div>

        <!-- Sidebar Navigation Menu -->
        <div class="sidebar-menu">
          <div class="sidebar-category-label hide-on-retract">Menu Categories</div>

          <!-- Category 1: Dashboard -->
          <button type="button" class="sidebar-nav-item active" onclick="switchTenantSection('tenantOverview', this)" title="Dashboard">
            <span class="nav-icon">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
              </svg>
            </span>
            <span class="hide-on-retract">Dashboard</span>
          </button>

          <!-- Category 2: Log Maintenance -->
          <button type="button" class="sidebar-nav-item" onclick="switchTenantSection('tenantMaintenance', this)" title="Log Maintenance">
            <span class="nav-icon">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.32l-3.276 3.277a3.004 3.004 0 0 1-2.25 2.25l-3.276-3.276a4.5 4.5 0 0 0-6.32 4.486c.048.58.072 1.193-.092 1.743" />
              </svg>
            </span>
            <span class="hide-on-retract">Log Maintenance</span>
          </button>

          <!-- Category 3: Upload Payment -->
          <button type="button" class="sidebar-nav-item" onclick="switchTenantSection('tenantPayments', this)" title="Upload Payment">
            <span class="nav-icon">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
              </svg>
            </span>
            <span class="hide-on-retract">Upload Payment</span>
          </button>
        </div>
      </div>

      <!-- Sidebar Footer -->
      <div class="sidebar-footer">
        <div class="hide-on-retract" style="font-size: 11.5px; color: var(--text-muted); text-align: center;">
          <?= htmlspecialchars($unit['unit_number']) ?> • ₱<?= number_format((float)$unit['monthly_rent'], 2) ?>/mo
        </div>
      </div>
    </aside>

    <!-- ===================================================================
         MAIN CONTENT AREA
         =================================================================== -->
    <main class="main-content">
      
      <!-- =================================================================
           TOP NAVIGATION BAR:
           1. Sidebar Toggle Button is strictly inside sidebar (removed from here)
           2. Upper Right Corner: Tenant profile, unit identifier, and Settings button
           ================================================================= -->
      <header class="top-nav-bar">
        <!-- Upper Right: Profile Trigger with Dropdown Menu -->
        <div class="top-nav-right">
          <!-- Notification Bell Trigger and Dropdown Panel -->
          <div class="header-notification-container" style="position: relative;">
            <button type="button" class="header-notification-btn" id="headerNotificationBtn" onclick="toggleNotificationPanel(event)" title="Notifications" aria-label="Notifications">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
              </svg>
              <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
            </button>

            <!-- Notification Dropdown Panel: Glassmorphism Design -->
            <div class="clay-settings-dropdown notification-dropdown-panel" id="notificationDropdown">
              <div class="notification-panel-header">
                <div class="notification-title-group" style="display: flex; align-items: center; gap: 8px;">
                  <h3 class="notification-heading">Notifications</h3>
                  <span class="notification-counter-pill" id="notificationUnreadPill" style="display: none;">0 new</span>
                </div>
                <button type="button" class="notification-mark-all-btn" id="btnMarkAllRead" onclick="markAllNotificationsAsRead(event)">
                  Mark all as read
                </button>
              </div>

              <!-- Notification items container -->
              <div class="notification-items-scroll" id="notificationListContainer">
                <div class="notification-loading-state" id="notificationLoading" style="padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
                  <span>Loading notifications...</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Circular Profile Icon Trigger with Dropdown Menu -->
          <div class="sidebar-settings-container header-settings-container" style="position: relative;">
            <div class="header-avatar-badge" id="headerAvatarBadge" onclick="toggleSettingsMenu(event)" role="button" tabindex="0" title="Account &amp; Settings" style="cursor: pointer;">
              <span class="header-avatar-initial"><?= htmlspecialchars($userInitial) ?></span>
            </div>

            <!-- Profile Dropdown: Clean Glassmorphism Panel -->
            <div class="clay-settings-dropdown fb-profile-dropdown" id="settingsDropdown">
              <!-- Top Profile Information Card (Display-Only) -->
              <div class="fb-profile-card">
                <!-- Main Profile Display: Avatar/Initial & Full Name (Non-clickable) -->
                <div class="fb-profile-main-row fb-profile-display-only">
                  <div class="fb-profile-avatar-circle">
                    <?php if (!empty($userAvatarUrl)): ?>
                      <img src="<?= $userAvatarUrl ?>" alt="<?= htmlspecialchars($user['name'] ?? 'Tenant') ?>">
                    <?php else: ?>
                      <span><?= htmlspecialchars($userInitial) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="fb-profile-main-info">
                    <span class="fb-profile-name" id="profileDropdownName"><?= htmlspecialchars($user['name'] ?? 'Tenant') ?></span>
                  </div>
                </div>
              </div>

              <!-- Options Directly Below Profile Information: Visually Separated -->
              <div class="fb-actions-group">
                <!-- Settings & Privacy Option (Positioned Directly Below Profile Information) -->
                <button type="button" class="fb-dropdown-action-item" id="settingsToggleBtn" onclick="openProfileView('accountInfo')" title="Settings &amp; privacy">
                  <div class="fb-action-icon-circle">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-700">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 0 1 0 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 0 1 0-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                  </div>
                  <span class="fb-action-label">Settings &amp; privacy</span>
                  <span class="fb-action-arrow">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                      <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                  </span>
                </button>

                <!-- Sign Out Option -->
                <a href="logout.php" class="fb-dropdown-action-item fb-action-item-danger" title="Sign Out">
                  <div class="fb-action-icon-circle">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                    </svg>
                  </div>
                  <span class="fb-action-label">Sign Out</span>
                  <span class="fb-action-arrow">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                      <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                  </span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <!-- CATEGORY 1: DASHBOARD OVERVIEW -->
      <section id="tenantOverview" class="app-view-section active-section">
        <!-- Dashboard Verification Notification Banner -->
        <div id="dashboardVerificationAlert" class="dashboard-verification-alert" style="display: none; margin: 0 0 24px 0; opacity: 1; transition: opacity 0.5s ease, transform 0.5s ease;"></div>

        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Welcome Home, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?>!</h1>
            <p>Here is your residency overview for <?= htmlspecialchars($unit['unit_number']) ?>.</p>
          </div>
          <div class="clay-badge badge-paid" style="font-size: 13px; padding: 8px 18px;">
            Monthly Rent: ₱<?= number_format((float)$unit['monthly_rent'], 2) ?>
          </div>
        </div>

        <!-- Clean & Minimal Dashboard Verification Warning Banner (Soft Red Claymorphism Style) -->
        <?php if (($user['verification_status'] ?? 'unverified') !== 'verified' && ($user['verification_status'] ?? '') !== 'pending'): ?>
        <div class="alert-clay alert-clay-danger verification-dashboard-warning" style="margin-bottom: 24px; padding: 16px 22px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; border-radius: var(--radius-xl); border: 2px solid #fecdd3; background: #fff1f2; box-shadow: var(--clay-card-shadow);">
          <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 42px; height: 42px; border-radius: 14px; background: #ffe4e6; color: #e11d48; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; box-shadow: var(--clay-button-shadow);">
              ⚠️
            </div>
            <div>
              <h4 style="font-size: 15px; font-weight: 800; color: #9f1239; margin: 0 0 3px 0;">Please verify your account</h4>
              <p style="font-size: 13px; color: #881337; margin: 0; line-height: 1.4;">Submit a valid government-issued ID and a quick live face scan to activate all tenant features.</p>
            </div>
          </div>
          <button type="button" class="clay-btn clay-btn-primary" style="padding: 10px 22px; font-size: 13px; background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 4px 4px 12px rgba(225, 29, 72, 0.35);" onclick="openProfileView('accountInfo')">
            <span>Verify Account →</span>
          </button>
        </div>
        <?php elseif (($user['verification_status'] ?? '') === 'pending'): ?>
        <div class="alert-clay alert-clay-info" style="margin-bottom: 24px; padding: 16px 20px;">
          <div class="alert-icon-clay">⏳</div>
          <div class="alert-body-clay">
            <h4>ID Verification Under Review</h4>
            <p>Your uploaded <strong><?= htmlspecialchars($user['id_type'] ?? 'ID document') ?></strong> is currently under review by the landlord.</p>
          </div>
        </div>
        <?php endif; ?>

        <div class="kpi-grid">
          <!-- Active Rent Status -->
          <div class="kpi-card clay-card-interactive">
            <div class="kpi-top">
              <span class="kpi-label">Current Rent Status</span>
              <div class="kpi-icon kpi-icon-revenue">💵</div>
            </div>
            <div class="kpi-value" style="font-size: 24px;">
              <?= $currentPayment ? strtoupper($currentPayment['status']) : 'PAID' ?>
            </div>
            <div class="kpi-subtitle">
              <span>₱<?= number_format((float)$unit['monthly_rent'], 2) ?> Due on 5th</span>
            </div>
          </div>

          <!-- Open Maintenance Tickets -->
          <div class="kpi-card clay-card-interactive">
            <div class="kpi-top">
              <span class="kpi-label">Active Work Orders</span>
              <div class="kpi-icon kpi-icon-cost">🛠️</div>
            </div>
            <div class="kpi-value">
              <?= count(array_filter($myTickets, fn($t) => in_array($t['status'], ['open', 'in_progress']))) ?>
            </div>
            <div class="kpi-subtitle"><span>Dispatched to Landlord</span></div>
          </div>

          <!-- Total Resolved -->
          <div class="kpi-card clay-card-interactive">
            <div class="kpi-top">
              <span class="kpi-label">Resolved Repairs</span>
              <div class="kpi-icon kpi-icon-efficiency">✓</div>
            </div>
            <div class="kpi-value">
              <?= count(array_filter($myTickets, fn($t) => in_array($t['status'], ['resolved', 'closed']))) ?>
            </div>
            <div class="kpi-subtitle"><span>Historical Completed</span></div>
          </div>
        </div>

        <!-- Recent Activity Cards -->
        <div class="analytics-grid">
          <div class="clay-card">
            <div class="widget-header">
              <div>
                <h3 class="widget-title">Recent Maintenance Log</h3>
                <p style="font-size: 12.5px; color: var(--text-muted);">Recent repair requests for <?= htmlspecialchars($unit['unit_number']) ?></p>
              </div>
              <button type="button" class="clay-btn clay-btn-secondary" style="font-size: 12px; padding: 6px 14px;" onclick="switchTenantSection('tenantMaintenance', document.querySelectorAll('.sidebar-nav-item')[1])">
                View All
              </button>
            </div>

            <div style="margin-top: 14px;">
              <?php if (empty($myTickets)): ?>
                <p style="font-size: 13px; color: var(--text-muted);">No maintenance requests logged yet.</p>
              <?php else: ?>
                <?php foreach (array_slice($myTickets, 0, 3) as $tk): ?>
                <div class="ticket-item">
                  <div>
                    <h4 style="font-size: 14.5px; font-weight: 700; color: #1e293b;"><?= htmlspecialchars($tk['title']) ?></h4>
                    <div class="ticket-meta">
                      <span class="clay-badge badge-<?= strtolower($tk['category']) ?>"><?= $tk['category'] ?></span>
                      <span class="ticket-date"><?= date('M j, Y', strtotime($tk['created_at'])) ?></span>
                    </div>
                  </div>
                  <span class="clay-badge badge-<?= $tk['status'] ?>"><?= strtoupper($tk['status']) ?></span>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="clay-card">
            <div class="widget-header">
              <div>
                <h3 class="widget-title">Payment Receipts</h3>
                <p style="font-size: 12.5px; color: var(--text-muted);">Verified receipts on file</p>
              </div>
              <button type="button" class="clay-btn clay-btn-secondary" style="font-size: 12px; padding: 6px 14px;" onclick="switchTenantSection('tenantPayments', document.querySelectorAll('.sidebar-nav-item')[2])">
                Upload New
              </button>
            </div>

            <div style="margin-top: 14px; display: flex; flex-direction: column; gap: 10px;">
              <?php if (empty($myPayments)): ?>
                <p style="font-size: 13px; color: var(--text-muted);">No payments recorded yet.</p>
              <?php else: ?>
                <?php foreach (array_slice($myPayments, 0, 3) as $pay): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--clay-input-bg); border-radius: var(--radius-md); box-shadow: var(--clay-sunken-shadow);">
                  <div>
                    <div style="font-size: 13.5px; font-weight: 700; color: #0f172a;"><?= date('F Y', strtotime($pay['billing_cycle'])) ?></div>
                    <div style="font-size: 11.5px; color: var(--text-muted);">₱<?= number_format((float)$pay['amount_paid'], 2) ?> on <?= $pay['payment_date'] ?? 'Pending' ?></div>
                  </div>
                  <span class="clay-badge badge-<?= $pay['status'] ?>"><?= strtoupper($pay['status']) ?></span>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </section>

      <!-- CATEGORY 2: LOG MAINTENANCE -->
      <section id="tenantMaintenance" class="app-view-section">
        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Log Maintenance Ticket</h1>
            <p>Submit repair requests for <?= htmlspecialchars($unit['unit_number']) ?> directly to Landlord Lead Dimaculangan.</p>
          </div>
        </div>

        <div class="tenant-grid">
          <!-- Left: Submit Form -->
          <div class="clay-card">
            <div class="widget-header">
              <div>
                <h2 class="widget-title">Submit Repair Request</h2>
                <p style="font-size: 13px; color: var(--text-muted);">Please provide details so we can dispatch repairs promptly</p>
              </div>
              <span class="widget-badge">Direct Dispatch</span>
            </div>

            <form id="maintenanceForm" style="margin-top: 18px;">
              <div class="form-group">
                <label for="ticketTitle" class="form-label">Issue Title <span class="req">*</span></label>
                <input type="text" id="ticketTitle" class="clay-input" placeholder="e.g. Bathroom sink pipe dripping or AC fan humming" required>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                  <label for="ticketCategory" class="form-label">Repair Category <span class="req">*</span></label>
                  <div class="select-wrapper">
                    <select id="ticketCategory" class="clay-select" required>
                      <option value="Plumbing">🚰 Plumbing</option>
                      <option value="Electrical">⚡ Electrical</option>
                      <option value="HVAC">❄️ HVAC / Climate</option>
                      <option value="Appliance">🍳 Appliance</option>
                      <option value="Structural">🚪 Structural</option>
                      <option value="General" selected>🔧 General</option>
                    </select>
                  </div>
                </div>

                <div class="form-group">
                  <label class="form-label">Severity Level</label>
                  <div class="priority-pills">
                    <button type="button" class="priority-pill" data-prio="low">Low</button>
                    <button type="button" class="priority-pill active" data-prio="medium">Med</button>
                    <button type="button" class="priority-pill" data-prio="high">High</button>
                    <button type="button" class="priority-pill" data-prio="urgent">Urgent</button>
                  </div>
                </div>
              </div>

              <div class="form-group">
                <label for="ticketDesc" class="form-label">Detailed Description <span class="req">*</span></label>
                <textarea id="ticketDesc" class="clay-textarea" placeholder="Explain what happened, location in the unit, and access instructions..." required></textarea>
              </div>

              <!-- Maintenance Photo Upload Field -->
              <div class="form-group" style="margin-top: 14px;">
                <label for="ticketPhotos" class="form-label" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                  <span>Attach Photos of Item Needing Repair (Optional)</span>
                  <span style="font-size: 11.5px; color: var(--text-muted); font-weight: 500;">JPG, PNG, WEBP</span>
                </label>
                <div class="file-upload-wrapper" style="position: relative;">
                  <input type="file" id="ticketPhotos" name="maintenance_photos[]" accept="image/*" multiple style="position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; z-index: 5;">
                  <label for="ticketPhotos" class="clay-btn clay-btn-secondary" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 16px; cursor: pointer;">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                      <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                    </svg>
                    <span id="ticketPhotosUploadLabel" style="font-size: 13px; font-weight: 700; color: #475569;">Attach Photos of Item</span>
                  </label>
                </div>
                <div id="ticketPhotosPreview" style="display: none; margin-top: 10px; gap: 8px; flex-wrap: wrap;"></div>
              </div>

              <button type="submit" class="clay-btn clay-btn-primary clay-btn-full" id="btnLogTicket" style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px;">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6" style="color: #ffffff;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.32l-3.276 3.277a3.004 3.004 0 0 1-2.25 2.25l-3.276-3.276a4.5 4.5 0 0 0-6.32 4.486c.048.58.072 1.193-.092 1.743" />
                </svg>
                <span>Dispatch Maintenance Ticket</span>
              </button>
            </form>
          </div>

          <!-- Right: Tickets History -->
          <div class="clay-card">
            <div class="widget-header">
              <div>
                <h2 class="widget-title">My Work Orders</h2>
                <p style="font-size: 13px; color: var(--text-muted);">Status tracking on your requests</p>
              </div>
            </div>

            <div style="margin-top: 16px;" id="ticketHistoryContainer">
              <?php if (empty($myTickets)): ?>
                <p style="font-size: 13px; color: var(--text-muted); text-align: center; padding: 20px;">No tickets logged yet.</p>
              <?php else: ?>
                <?php foreach ($myTickets as $tk): ?>
                <div class="ticket-item">
                  <div>
                    <h4 style="font-size: 15px; font-weight: 700; color: #1e293b;"><?= htmlspecialchars($tk['title']) ?></h4>
                    <div class="ticket-meta">
                      <span class="clay-badge badge-<?= strtolower($tk['category']) ?>"><?= $tk['category'] ?></span>
                      <span class="ticket-date"><?= date('M j, Y', strtotime($tk['created_at'])) ?></span>
                      <?php if (!empty($tk['photo_path'])): ?>
                        <?php 
                          $pList = explode(',', $tk['photo_path']);
                          foreach ($pList as $pIdx => $pItem): 
                            $pItem = trim($pItem);
                            if (!$pItem) continue;
                        ?>
                          <a href="<?= htmlspecialchars($pItem) ?>" target="_blank" class="btn-inspect-proof" style="font-size: 11px; padding: 2px 7px; margin-left: 4px;">
                            📷 Photo <?= count($pList) > 1 ? ($pIdx + 1) : '' ?>
                          </a>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                  <span class="clay-badge badge-<?= $tk['status'] ?>"><?= strtoupper($tk['status']) ?></span>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </section>

      <!-- CATEGORY 3: UPLOAD PAYMENT -->
      <section id="tenantPayments" class="app-view-section">
        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Rent Payment &amp; Receipt Drop-Zone</h1>
            <p>Upload bank transfer receipts or GCash confirmation slips for <?= htmlspecialchars($unit['unit_number']) ?>.</p>
          </div>
        </div>

        <div class="tenant-grid">
          <!-- Drop-Zone Form Card -->
          <div class="clay-card">
            <div class="widget-header">
              <div>
                <h2 class="widget-title">Upload Payment Proof</h2>
                <p style="font-size: 13px; color: var(--text-muted);">Submit verification for monthly rent</p>
              </div>
              <span class="widget-badge">Secure Ledger</span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px; margin-bottom: 18px;">
              <div class="form-group" style="margin-bottom: 0;">
                <label for="payCycle" class="form-label">Billing Cycle</label>
                <div class="select-wrapper">
                  <select id="payCycle" class="clay-select">
                    <option value="2026-10-01" selected>October 2026</option>
                    <option value="2026-11-01">November 2026</option>
                    <option value="2026-09-01">September 2026</option>
                  </select>
                </div>
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label for="payAmount" class="form-label">Amount Paid</label>
                <input type="number" id="payAmount" class="clay-input" value="<?= number_format((float)$unit['monthly_rent'], 2, '.', '') ?>" step="0.01">
              </div>
            </div>

            <!-- Clay Drop-Zone -->
            <div class="clay-dropzone" id="receiptDropZone">
              <input type="file" id="receiptFileInput" class="dropzone-input" accept=".pdf,.png,.jpg,.jpeg,.webp">
              <div class="dropzone-icon">📄</div>
              <h3 class="dropzone-title">Drag &amp; drop rent receipt here</h3>
              <p class="dropzone-desc">or <strong>click to browse device</strong></p>
              <div style="display: flex; justify-content: center; gap: 6px; margin-top: 14px;">
                <span class="clay-badge badge-low" style="font-size: 10px;">PDF</span>
                <span class="clay-badge badge-low" style="font-size: 10px;">PNG</span>
                <span class="clay-badge badge-low" style="font-size: 10px;">JPG</span>
                <span class="clay-badge badge-low" style="font-size: 10px;">Max 8MB</span>
              </div>
            </div>

            <!-- File Preview Card -->
            <div class="file-preview-card" id="filePreviewCard">
              <div class="file-preview-info">
                <div class="file-preview-icon">📎</div>
                <div>
                  <div class="file-name" id="previewFileName">receipt.pdf</div>
                  <div class="file-size" id="previewFileSize">1.2 MB</div>
                </div>
              </div>
              <button type="button" class="btn-remove-file" id="btnRemoveFile">✕</button>
            </div>

            <!-- Progress Track -->
            <div class="clay-progress-wrapper" id="progressWrapper">
              <div class="clay-progress-track">
                <div class="clay-progress-fill" id="progressFill"></div>
              </div>
            </div>

            <button type="button" class="clay-btn clay-btn-success clay-btn-full" id="btnUploadReceipt" style="margin-top: 20px;">
              <span>📤 Submit Rent Payment Proof</span>
            </button>
          </div>

          <!-- Payment History Card -->
          <div class="clay-card">
            <div class="widget-header">
              <div>
                <h2 class="widget-title">Historical Receipts Ledger</h2>
                <p style="font-size: 13px; color: var(--text-muted);">Verified receipts on file for <?= htmlspecialchars($unit['unit_number']) ?></p>
              </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px;">
              <?php if (empty($myPayments)): ?>
                <p style="font-size: 13px; color: var(--text-muted); text-align: center; padding: 20px;">No payment records found.</p>
              <?php else: ?>
                <?php foreach ($myPayments as $py): ?>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; padding: 14px 18px; background: var(--clay-card-bg); border-radius: var(--radius-md); box-shadow: var(--clay-card-shadow-sm); border: 1px solid var(--clay-border); gap: 12px;">
                  <div>
                    <div style="font-size: 14px; font-weight: 800; color: #0f172a;"><?= date('F Y', strtotime($py['billing_cycle'])) ?></div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                      ₱<?= number_format((float)$py['amount_paid'], 2) ?> paid on <?= $py['payment_date'] ?? 'Pending' ?>
                    </div>
                    <?php if (!empty($py['verification_notes'])): ?>
                      <div style="font-size: 11.5px; color: <?= $py['status'] === 'rejected' ? '#b91c1c' : '#047857' ?>; margin-top: 6px; font-weight: 600;">
                        Feedback: <?= htmlspecialchars($py['verification_notes']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
                    <span class="clay-badge badge-<?= strtolower($py['status']) ?>">
                      <?= $py['status'] === 'pending' ? 'PENDING VERIFICATION' : ($py['status'] === 'approved' ? '✓ VERIFIED' : strtoupper($py['status'])) ?>
                    </span>
                    <?php if ($py['receipt_filename']): ?>
                      <a href="uploads/receipts/<?= htmlspecialchars($py['receipt_filename']) ?>" target="_blank" class="btn-inspect-proof">📄 Proof</a>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </section>

      <!-- CATEGORY 4: RESIDENT PROFILE (VIEW WITH INTERNAL SUB-SIDEBAR) -->
      <section id="tenantProfile" class="app-view-section">
        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Resident Profile &amp; Settings</h1>
            <p>Manage your account information, residency verification, and login security.</p>
          </div>
        </div>

        <div class="profile-view-container">
          <!-- Internal Sub-Sidebar with exactly two options -->
          <div class="profile-sub-sidebar">
            <button type="button" class="profile-subnav-item active" id="tenantSubnavAccount" onclick="switchProfileSubTab('accountInfo', this)">
              <span class="subnav-icon">👤</span>
              <span>Account Information</span>
            </button>
            <button type="button" class="profile-subnav-item" id="tenantSubnavPassword" onclick="switchProfileSubTab('changePassword', this)">
              <span class="subnav-icon">🔒</span>
              <span>Change Password</span>
            </button>
          </div>

          <!-- Sub-Tab Content Area -->
          <div class="profile-content-area">
            
            <!-- Sub-Tab 1: Account Information -->
            <div id="paneAccountInfo" class="profile-tab-pane active-pane">
              <!-- Verification Notification Bar inside Account Information (6s duration, Soft Red Warning / Soft Green Success) -->
              <div id="accountInfoVerificationAlert" class="account-info-verification-alert" style="display: none; margin-bottom: 22px; opacity: 1; transition: opacity 0.5s ease, transform 0.5s ease;"></div>

              <div class="clay-card">
                <div class="clay-card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; flex-wrap: wrap; gap: 12px;">
                  <div>
                    <h2 class="clay-card-title">Account Information</h2>
                    <p class="clay-card-subtitle">Personal records, unit assignment, and identity verification</p>
                  </div>
                  <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="clay-btn clay-btn-secondary" style="padding: 7px 16px; font-size: 12.5px;" onclick="openEditAccountInfoModal()">
                      ✏️ Edit Information
                    </button>
                    <?php if (($user['verification_status'] ?? 'unverified') === 'verified'): ?>
                      <span class="clay-badge badge-verified" style="font-size: 13px; padding: 7px 16px;">
                        <span>✓</span> Verified Resident
                      </span>
                    <?php elseif (($user['verification_status'] ?? '') === 'pending'): ?>
                      <span class="clay-badge badge-pending" style="font-size: 13px; padding: 7px 16px;">
                        <span>⏳</span> Pending Review
                      </span>
                    <?php else: ?>
                      <span class="clay-badge badge-unverified" style="font-size: 13px; padding: 7px 16px;">
                        <span>⚠️</span> Unverified Account
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="detail-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin-bottom: 24px;">
                  <div class="detail-item">
                    <span class="detail-label">Full Name:</span>
                    <span class="detail-value" id="profileFullName" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($user['name']) ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Username:</span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($user['username']) ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Assigned Unit:</span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($unit['unit_number']) ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Monthly Rent:</span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;">₱<?= number_format((float)$unit['monthly_rent'], 2) ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Email Address:</span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($user['email'] ?? 'Not set') ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Phone Number:</span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($user['phone'] ?? 'Not set') ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Emergency Contact:</span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($user['emergency_contact_name'] ?? $user['emergency_contact'] ?? 'Not set') ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Emergency Phone:</span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($user['emergency_contact_phone'] ?? 'Not set') ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Date of Birth:</span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;">
                      <?= !empty($user['birthday']) ? date('F j, Y', strtotime($user['birthday'])) : 'Not set' ?>
                    </span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Role:</span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700; text-transform: capitalize;"><?= htmlspecialchars($user['role']) ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Account Verification:</span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;">
                      <?= ucfirst(htmlspecialchars($user['verification_status'] ?? 'unverified')) ?>
                    </span>
                  </div>
                </div>

                <!-- Verification Action / Status Container -->
                <div style="margin-top: 24px; padding-top: 20px; border-top: 2px dashed rgba(166, 180, 204, 0.4);">
                  <?php if (($user['verification_status'] ?? 'unverified') === 'unverified'): ?>
                    <div style="background: #fff1f2; border: 1.5px solid #fecdd3; border-radius: var(--radius-lg); padding: 18px 22px;">
                      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                        <div>
                          <?php if (!empty($user['verification_notes'])): ?>
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                              <span style="font-size: 20px; color: #e11d48;">✕</span>
                              <div style="font-size: 15px; font-weight: 800; color: #9f1239;">Identity Verification Failed — Account Remains Unverified</div>
                            </div>
                            <div style="font-size: 13px; color: #881337; margin-bottom: 6px;">
                              <?= htmlspecialchars(str_replace('Rejected: ', '', $user['verification_notes'])) ?>
                            </div>
                            <div style="font-size: 12px; color: #9f1239; font-weight: 600;">
                              Your account status remains <strong>Unverified</strong>. Please verify your account again.
                            </div>
                          <?php else: ?>
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                              <span style="font-size: 20px;">🪪</span>
                              <div style="font-size: 15px; font-weight: 800; color: #9f1239;">Identity Verification Needed</div>
                            </div>
                            <div style="font-size: 13px; color: #881337;">Submit a photo of your valid government or student ID and complete a live face scan to verify your identity.</div>
                          <?php endif; ?>
                        </div>
                        <button type="button" class="clay-btn clay-btn-primary" id="btnVerifyAccount" onclick="openVerifyAccountModal()" style="background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 6px 6px 14px rgba(225, 29, 72, 0.35); padding: 10px 22px;">
                          <span><?= !empty($user['verification_notes']) ? '🔄 Verify Account Again' : '🪪 Verify Account' ?></span>
                        </button>
                      </div>
                    </div>
                  <?php elseif (($user['verification_status'] ?? '') === 'rejected'): ?>
                    <div style="background: #fff1f2; border: 1.5px solid #fecdd3; border-radius: var(--radius-lg); padding: 18px 22px;">
                      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                        <div>
                          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                            <span style="font-size: 20px;">✕</span>
                            <div style="font-size: 15px; font-weight: 800; color: #9f1239;">Identity Verification Failed — Account Remains Unverified</div>
                          </div>
                          <div style="font-size: 13px; color: #881337; margin-bottom: 6px;">
                            <?= htmlspecialchars(str_replace('Rejected: ', '', $user['verification_notes'] ?? 'Your ID verification failed validation.')) ?>
                          </div>
                        </div>
                        <button type="button" class="clay-btn clay-btn-primary" id="btnVerifyAccount" onclick="openVerifyAccountModal()" style="background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 6px 6px 14px rgba(225, 29, 72, 0.35); padding: 10px 22px;">
                          <span>🔄 Verify Account Again</span>
                        </button>
                      </div>
                    </div>
                  <?php elseif (($user['verification_status'] ?? '') === 'pending'): ?>
                    <div style="background: #fefce8; border: 1.5px solid #fef08a; border-radius: var(--radius-lg); padding: 18px 22px;">
                      <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                        <span style="font-size: 20px;">⏳</span>
                        <div style="font-size: 15px; font-weight: 800; color: #854d0e;">ID Verification Under Landlord Review</div>
                      </div>
                      <div style="font-size: 13px; color: #713f12;">
                        Submitted ID: <strong><?= htmlspecialchars($user['id_type'] ?? 'ID Document') ?></strong> on <?= !empty($user['id_submitted_at']) ? date('M j, Y g:i A', strtotime($user['id_submitted_at'])) : 'Recently' ?>.
                        <?php if (!empty($user['id_photo_filename'])): ?>
                          <a href="uploads/id_verifications/<?= htmlspecialchars($user['id_photo_filename']) ?>" target="_blank" style="margin-left: 10px; color: var(--clay-primary-dark); font-weight: 700; text-decoration: underline;">View Uploaded ID Photo</a>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php else: ?>
                    <div style="background: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: var(--radius-lg); padding: 18px 22px;">
                      <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                        <span style="font-size: 20px;">✅</span>
                        <div style="font-size: 15px; font-weight: 800; color: #065f46;">Account Fully Verified</div>
                      </div>
                      <div style="font-size: 13px; color: #047857;">
                        Verified with <strong><?= htmlspecialchars($user['id_type'] ?? 'Official ID') ?></strong>.
                        <?php if (!empty($user['id_photo_filename'])): ?>
                          <a href="uploads/id_verifications/<?= htmlspecialchars($user['id_photo_filename']) ?>" target="_blank" style="margin-left: 10px; color: #065f46; font-weight: 700; text-decoration: underline;">View ID Document</a>
                        <?php endif; ?>
                        <?php if (!empty($user['live_photo_filename'])): ?>
                          <a href="uploads/id_verifications/<?= htmlspecialchars($user['live_photo_filename']) ?>" target="_blank" style="margin-left: 10px; color: #065f46; font-weight: 700; text-decoration: underline;">View Live Face Scan</a>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <!-- Sub-Tab 2: Change Password -->
            <div id="paneChangePassword" class="profile-tab-pane">
              <div class="clay-card" style="max-width: 600px;">
                <div class="clay-card-header" style="margin-bottom: 22px;">
                  <h2 class="clay-card-title">Change Password</h2>
                  <p class="clay-card-subtitle">Keep your account secure by updating your password regularly</p>
                </div>

                <form id="changePasswordForm">
                  <div class="form-group">
                    <label class="form-label" for="profCurrentPass">Current Password <span class="req">*</span></label>
                    <input type="password" id="profCurrentPass" class="clay-input" placeholder="Enter existing password" required autocomplete="current-password">
                  </div>

                  <div class="form-group">
                    <label class="form-label" for="profNewPass">New Password <span class="req">*</span></label>
                    <input type="password" id="profNewPass" class="clay-input" placeholder="At least 6 characters" minlength="6" required autocomplete="new-password">
                  </div>

                  <div class="form-group">
                    <label class="form-label" for="profConfirmPass">Confirm New Password <span class="req">*</span></label>
                    <input type="password" id="profConfirmPass" class="clay-input" placeholder="Confirm new password" minlength="6" required autocomplete="new-password">
                  </div>

                  <div style="margin-top: 24px;">
                    <button type="submit" class="clay-btn clay-btn-primary" id="btnSavePassword" style="min-width: 180px;">
                      Update Password
                    </button>
                  </div>
                </form>
              </div>
            </div>

          </div>
        </div>
      </section>

    </main>

  </div>

  <!-- =====================================================================
       MODAL: EDIT TENANT ACCOUNT INFORMATION
       Allows tenant to update phone, email, and emergency contact details
       ===================================================================== -->
  <div class="modal-backdrop" id="editAccountInfoModal" style="display: none; pointer-events: none;">
    <div class="clay-modal" style="max-width: 500px;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
          <h2 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 2px 0;">Edit Account Information</h2>
          <p style="font-size: 12.5px; color: var(--text-muted); margin: 0;">Update your contact details and emergency contact records</p>
        </div>
        <button type="button" class="btn-remove-file" onclick="closeEditAccountInfoModal()" title="Close">✕</button>
      </div>

      <form id="editAccountInfoForm">
        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label" for="editEmail">Email Address</label>
          <input type="email" id="editEmail" name="email" class="clay-input" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="your.email@example.com">
        </div>

        <div class="form-group" style="margin-bottom: 16px;">
          <label class="form-label" for="editPhone">Phone Number</label>
          <input type="tel" id="editPhone" name="phone" class="clay-input" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+63 900 000 0000">
        </div>

        <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 14px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 18px;">
          <div style="font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
            <span>🚨</span> Emergency Contact Details
          </div>
          <div class="form-group" style="margin-bottom: 10px;">
            <label class="form-label" for="editEmergencyName" style="font-size: 11.5px;">Contact Person's Full Name</label>
            <input type="text" id="editEmergencyName" name="emergency_contact_name" class="clay-input" value="<?= htmlspecialchars($user['emergency_contact_name'] ?? $user['emergency_contact'] ?? '') ?>" placeholder="e.g. Maria Santos">
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" for="editEmergencyRel" style="font-size: 11.5px;">Relationship</label>
              <input type="text" id="editEmergencyRel" name="emergency_contact_relationship" class="clay-input" value="<?= htmlspecialchars($user['emergency_contact_relationship'] ?? '') ?>" placeholder="e.g. Mother, Spouse">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" for="editEmergencyPhone" style="font-size: 11.5px;">Contact Number</label>
              <input type="tel" id="editEmergencyPhone" name="emergency_contact_phone" class="clay-input" value="<?= htmlspecialchars($user['emergency_contact_phone'] ?? '') ?>" placeholder="+63 900 000 0000">
            </div>
          </div>
        </div>

        <div style="display: flex; gap: 12px; margin-top: 20px;">
          <button type="button" class="clay-btn clay-btn-secondary" style="flex: 1;" onclick="closeEditAccountInfoModal()">Cancel</button>
          <button type="submit" class="clay-btn clay-btn-primary" id="btnSaveAccountInfo" style="flex: 2;">Save Information</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: MULTI-STEP TENANT ID & LIVE FACE VERIFICATION INTERFACE
       ===================================================================== -->
  <div class="modal-backdrop" id="verifyAccountModal" style="display: none; pointer-events: none;">
    <div class="clay-modal" style="max-width: 540px; width: 100%;">
      <!-- Modal Header -->
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <div>
          <h2 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 2px 0;">Verify Your Account</h2>
          <p style="font-size: 12.5px; color: var(--text-muted); margin: 0;">Multi-step identity &amp; live biometric verification</p>
        </div>
        <button type="button" class="btn-remove-file" onclick="closeVerifyAccountModal()" title="Close">✕</button>
      </div>

      <!-- Step Progress Indicator: 1. Upload ID -> 2. Live Testing -->
      <div class="verification-step-indicator" style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px; padding: 6px; background: rgba(226, 232, 240, 0.6); border-radius: 14px;">
        <div id="stepPill1" class="step-pill active" style="flex: 1; padding: 8px 12px; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 12px; font-weight: 800; background: var(--clay-surface); color: var(--clay-primary-dark); box-shadow: var(--clay-button-shadow); transition: all 0.2s ease;">
          <span style="width: 22px; height: 22px; border-radius: 50%; background: var(--clay-primary); color: white; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">1</span>
          <span>Upload Valid ID</span>
        </div>
        <div style="color: var(--text-muted); font-size: 14px; font-weight: 700;">→</div>
        <div id="stepPill2" class="step-pill" style="flex: 1; padding: 8px 12px; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 12px; font-weight: 800; background: transparent; color: var(--text-muted); transition: all 0.2s ease;">
          <span style="width: 22px; height: 22px; border-radius: 50%; background: #cbd5e1; color: #475569; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">2</span>
          <span>Live Testing</span>
        </div>
      </div>

      <!-- Error / Alert Banner inside modal -->
      <div id="verifyModalAlert" style="display: none; margin-bottom: 16px; padding: 12px 16px; border-radius: var(--radius-md); font-size: 12.5px; font-weight: 600; line-height: 1.4; border: 1.5px solid #fecdd3; background: #fff1f2; color: #9f1239;"></div>

      <form id="verifyAccountForm" enctype="multipart/form-data">
        <!-- ================= STEP 1: SELECT ID TYPE & UPLOAD ID ================= -->
        <div id="verifyStep1View">
          <!-- Dropdown menu to select a Valid ID Type -->
          <div class="form-group" style="margin-bottom: 16px;">
            <label class="form-label" for="idTypeSelect">Valid ID Type <span class="req">*</span></label>
            <select id="idTypeSelect" name="id_type" class="clay-select" required>
              <option value="" disabled selected> Select Valid ID Type </option>
              <option value="National ID">National ID</option>
              <option value="Driver's License">Driver's License</option>
              <option value="Passport">Passport</option>
              <option value="UMID">UMID</option>
              <option value="SSS / GSIS ID">SSS / GSIS ID</option>
              <option value="PRC ID">PRC ID</option>
              <option value="Postal ID">Postal ID</option>
              <option value="Voter's ID">Voter's ID</option>
              <option value="PhilHealth ID">PhilHealth ID</option>
              <option value="Student ID">Student ID</option>
              <option value="Other Valid Government ID">Other Valid Government ID</option>
            </select>
          </div>

          <!-- File Upload Field to Submit a Photo of the ID -->
          <div class="form-group">
            <label class="form-label">Photo of Valid ID <span class="req">*</span></label>
            <div class="drop-zone" id="idDropZone" style="cursor: pointer; padding: 24px 16px;">
              <div class="drop-zone-icon" style="font-size: 34px;">🪪</div>
              <div class="drop-zone-text" style="font-size: 14px; font-weight: 700;">Click to browse or drag &amp; drop ID photo</div>
              <div class="drop-zone-hint" style="font-size: 12px; margin-top: 4px;">Supports JPG, PNG, WEBP (Max 10MB)</div>
            </div>
            <input type="file" id="idFileInput" name="id_photo" accept="image/jpeg,image/png,image/webp" style="display: none;" required>

            <!-- Visual ID Image Preview Card (Immediate confirmation before clicking Next) -->
            <div id="idImagePreviewCard" style="display: none; margin-top: 14px; border-radius: var(--radius-lg); overflow: hidden; background: #0f172a; box-shadow: var(--clay-sunken-shadow); border: 1.5px solid rgba(203, 213, 225, 0.8);">
              <div style="position: relative; width: 100%; height: 210px; background: #0f172a; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                <!-- Visual ID image preview -->
                <img id="idVisualPreviewImg" src="" alt="Uploaded ID Preview" style="width: 100%; height: 100%; object-fit: contain; display: block;">
                <!-- Remove / Change ID Button -->
                <button type="button" class="btn-remove-file" id="btnRemoveIdFile" onclick="removeIdFile(event)" title="Change or Remove ID" style="position: absolute; top: 10px; right: 10px; background: rgba(15, 23, 42, 0.75); color: #ffffff; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; cursor: pointer; border: 1.5px solid rgba(255, 255, 255, 0.3); backdrop-filter: blur(4px);">✕</button>
              </div>
              <!-- File Info Bar -->
              <div style="padding: 10px 14px; background: var(--clay-surface); display: flex; align-items: center; justify-content: space-between; gap: 10px; border-top: 1px solid rgba(226, 232, 240, 0.8);">
                <div style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                  <span style="font-size: 18px;">🪪</span>
                  <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <div id="idPreviewFileName" style="font-size: 12.5px; font-weight: 700; color: #0f172a; overflow: hidden; text-overflow: ellipsis;"></div>
                    <div id="idPreviewFileSize" style="font-size: 11px; color: var(--text-muted);"></div>
                  </div>
                </div>
                <span class="clay-badge badge-low" style="font-size: 11px; padding: 4px 10px; font-weight: 700;">✓ ID Attached</span>
              </div>
            </div>
          </div>

          <div style="display: flex; gap: 12px; margin-top: 24px;">
            <button type="button" class="clay-btn clay-btn-secondary" style="flex: 1;" onclick="closeVerifyAccountModal()">Cancel</button>
            <button type="button" class="clay-btn clay-btn-primary" style="flex: 2;" id="btnNextStep1" onclick="goToVerificationStep(2)">
              <span>Next: Live Testing →</span>
            </button>
          </div>
        </div>

        <!-- ================= STEP 2: DEDICATED LIVE TESTING & AUTO-CAPTURE ================= -->
        <div id="verifyStep2View" style="display: none;">
          <div style="text-align: center; margin-bottom: 12px;">
            <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin: 0 0 4px 0;">Step 2: Dedicated Live Testing</h3>
            <p style="font-size: 12px; color: var(--text-muted); margin: 0;">Perform the physical action to verify liveness and automatically capture your face photo.</p>
          </div>

          <!-- Active Movement Instruction Banner -->
          <div id="livenessInstructionBanner" style="margin-bottom: 14px; padding: 12px 16px; border-radius: var(--radius-lg); background: #f0fdf4; border: 1.5px solid #86efac; display: flex; align-items: center; gap: 12px; box-shadow: var(--clay-button-shadow);">
            <div style="width: 36px; height: 36px; border-radius: 10px; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; flex-shrink: 0;">
              👀
            </div>
            <div>
              <div style="font-size: 11px; font-weight: 800; color: #15803d; text-transform: uppercase; letter-spacing: 0.5px;">Instruction</div>
              <div id="livenessChallengeText" style="font-size: 14px; font-weight: 800; color: #166534;">
                Blink your eyes or Move your head to the left or right
              </div>
            </div>
          </div>

          <!-- Live Webcam Feed for Pure Landmark Motion Analysis & Auto-Capture -->
          <div style="position: relative; width: 100%; max-width: 380px; height: 260px; margin: 0 auto; border-radius: 18px; overflow: hidden; background: #0f172a; box-shadow: var(--clay-sunken-shadow); display: flex; align-items: center; justify-content: center;">
            <video id="livenessVideo" autoplay playsinline muted style="width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); -webkit-transform: scaleX(-1);"></video>

            <!-- Hidden canvas to grab auto-capture snapshot -->
            <canvas id="livenessCaptureCanvas" style="display: none;"></canvas>

            <!-- Guide oval cutout -->
            <div class="gcash-camera-mask face-oval-guide">
              <div id="livenessOvalRing" class="face-oval-ring"></div>
            </div>

            <!-- Dynamic Status Pill -->
            <div id="livenessStatusPill" class="face-status-pill">
              <span id="livenessStatusIcon">👀</span>
              <span id="livenessStatusText">Blink your eyes or move your head</span>
            </div>

            <!-- Loading overlay when finalizing -->
            <div id="livenessFinalizingOverlay" style="display: none; position: absolute; inset: 0; background: rgba(15, 23, 42, 0.92); z-index: 25; flex-direction: column; align-items: center; justify-content: center; gap: 10px; color: white; padding: 20px; text-align: center;">
              <div style="font-size: 34px;">✨</div>
              <div style="font-size: 15px; font-weight: 800; color: #34d399;" id="livenessFinalizingTitle">Action Confirmed!</div>
              <div style="font-size: 12.5px; color: #94a3b8;" id="livenessFinalizingSub">Comparing face with your ID...</div>
            </div>
          </div>

          <!-- Subtext notice -->
          <div style="text-align: center; margin-top: 14px;">
            <div style="font-size: 12px; font-weight: 700; color: #64748b;" id="livenessSubNotice">
              Tracking physical movement in real-time. Automated detection will complete your verification.
            </div>
          </div>

          <!-- Navigation Buttons: Back button directly to Step 1 -->
          <div style="display: flex; gap: 12px; margin-top: 20px;">
            <button type="button" class="clay-btn clay-btn-secondary" style="width: 100%;" id="btnBackToStep1" onclick="goToVerificationStep(1)">
              <span>← Back to ID</span>
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Clay Toast Notification -->
  <div class="clay-toast" id="clayToast">
    <span class="toast-icon" id="toastIcon">✨</span>
    <span class="toast-message" id="toastMessage">Done</span>
  </div>

  <script>
    let currentPrio = 'medium';
    let attachedFile = null;

    let toastTimeout = null;
    function showToast(msg, type = 'success', duration = 3000) {
      const t = document.getElementById('clayToast');
      const icon = document.getElementById('toastIcon');
      const msgEl = document.getElementById('toastMessage');
      if (msgEl) msgEl.textContent = msg;
      if (icon) {
        icon.textContent = (type === 'error') ? '✕' : ((type === 'info') ? 'ℹ️' : '✨');
      }
      if (t) {
        t.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        t.classList.add('show');
        if (toastTimeout) clearTimeout(toastTimeout);
        toastTimeout = setTimeout(() => {
          t.classList.remove('show');
        }, duration);
      }
    }

    // Single Unified Retractable Sidebar Toggle System (Strictly 1 Toggle in DOM)
    function toggleSidebar() {
      const sidebar = document.getElementById('appSidebar');
      const overlay = document.querySelector('.sidebar-overlay');
      if (!sidebar) return;
      if (window.innerWidth <= 960) {
        sidebar.classList.toggle('mobile-open');
        if (overlay) {
          overlay.classList.toggle('active');
          if (overlay.classList.contains('active')) {
            overlay.style.display = 'block';
            overlay.style.pointerEvents = 'auto';
          } else {
            overlay.style.display = 'none';
            overlay.style.pointerEvents = 'none';
          }
        }
      } else {
        sidebar.classList.toggle('retracted');
      }
    }

    function closeSidebarMobile() {
      const sidebar = document.getElementById('appSidebar');
      const overlay = document.querySelector('.sidebar-overlay');
      if (sidebar) sidebar.classList.remove('mobile-open');
      if (overlay) {
        overlay.classList.remove('active');
        overlay.style.display = 'none';
        overlay.style.pointerEvents = 'none';
      }
    }

    // Sidebar Category Switcher: Sidebar strictly remains open and visible when clicking category tabs/links
    function switchTenantSection(sectionId, btn, updateUrl = true) {
      if (!sectionId) return;

      if (sectionId === 'tenantProfile') {
        openProfileView('accountInfo', updateUrl);
        return;
      }

      document.querySelectorAll('.app-view-section').forEach(s => s.classList.remove('active-section'));
      document.querySelectorAll('.sidebar-nav-item').forEach(b => b.classList.remove('active'));

      const target = document.getElementById(sectionId);
      if (target) target.classList.add('active-section');
      
      let activeBtn = btn;
      if (!activeBtn) {
        activeBtn = document.querySelector(`.sidebar-nav-item[onclick*="${sectionId}"]`);
      }
      if (activeBtn) activeBtn.classList.add('active');

      if (updateUrl && window.history && window.history.replaceState) {
        history.replaceState(null, '', `tenant_portal.php?section=${sectionId}`);
      }

      // Hide Settings button when leaving profile section
      updateProfileSettingsVisibility(false);

      closeSettingsMenu();
    }

    // Synchronize user profile information and settings state
    function updateProfileSettingsVisibility(isProfileOpen) {
      const nameEl = document.getElementById('headerUserName');
      if (nameEl) {
        nameEl.textContent = <?= json_encode($user['name'] ?? 'Resident') ?>;
      }
      const profNameEl = document.getElementById('profileFullName');
      if (profNameEl) {
        profNameEl.textContent = <?= json_encode($user['name'] ?? 'Resident') ?>;
      }
      const dropNameEl = document.getElementById('profileDropdownName');
      if (dropNameEl) {
        dropNameEl.textContent = <?= json_encode($user['name'] ?? 'Resident') ?>;
      }
    }

    // Settings Dropdown Popover Control
    function toggleSettingsMenu(e) {
      if (e && typeof e.stopPropagation === 'function') {
        e.stopPropagation();
      }
      if (typeof closeNotificationPanel === 'function') {
        closeNotificationPanel();
      }
      const d = document.getElementById('settingsDropdown');
      if (d) d.classList.toggle('show');
    }

    function closeSettingsMenu() {
      const d = document.getElementById('settingsDropdown');
      if (d) d.classList.remove('show');
    }

    // Profile View & Internal Sub-Sidebar Navigation (Sidebar strictly remains open and visible)
    function openProfileView(tab = 'accountInfo', updateUrl = true) {
      document.querySelectorAll('.app-view-section').forEach(s => s.classList.remove('active-section'));
      document.querySelectorAll('.sidebar-nav-item').forEach(b => b.classList.remove('active'));

      const profSec = document.getElementById('tenantProfile');
      if (profSec) profSec.classList.add('active-section');

      // Show Settings button along with user profile information
      updateProfileSettingsVisibility(true);

      switchProfileSubTab(tab, null, updateUrl);
      closeSettingsMenu();
    }

    function switchProfileSubTab(tabName, clickedBtn = null, updateUrl = true) {
      document.querySelectorAll('.profile-tab-pane').forEach(p => p.classList.remove('active-pane'));
      document.querySelectorAll('.profile-subnav-item').forEach(b => b.classList.remove('active'));

      if (tabName === 'accountInfo') {
        const pane = document.getElementById('paneAccountInfo');
        if (pane) pane.classList.add('active-pane');
        const btn = clickedBtn || document.getElementById('tenantSubnavAccount');
        if (btn) btn.classList.add('active');
      } else if (tabName === 'changePassword') {
        const pane = document.getElementById('paneChangePassword');
        if (pane) pane.classList.add('active-pane');
        const btn = clickedBtn || document.getElementById('tenantSubnavPassword');
        if (btn) btn.classList.add('active');
      }

      if (updateUrl && window.history && window.history.replaceState) {
        history.replaceState(null, '', `tenant_portal.php?section=tenantProfile&tab=${tabName}`);
      }
    }

    // Edit Account Information Modal Controls
    function openEditAccountInfoModal() {
      const modal = document.getElementById('editAccountInfoModal');
      if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('open');
        modal.style.pointerEvents = 'auto';
      }
    }

    function closeEditAccountInfoModal() {
      const modal = document.getElementById('editAccountInfoModal');
      if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
      }
    }

    // =========================================================================
    // CORE SIDEBAR & SETTINGS EVENT LISTENERS
    // Attached in DOMContentLoaded immediately at the top of script execution
    // to guarantee the hamburger toggle and settings buttons are interactive
    // without being blocked by deferred libraries, OCR, or face biometrics.
    // =========================================================================
    function setupSidebarAndSettingsListeners() {
      try {
        // 1. Sidebar Toggle Button (Hamburger Menu)
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        if (sidebarToggleBtn) {
          sidebarToggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
          });
        }

        // 2. Settings & Privacy Button inside Dropdown
        const settingsToggleBtn = document.getElementById('settingsToggleBtn');
        if (settingsToggleBtn) {
          settingsToggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            openProfileView('accountInfo');
          });
        }

        // Header Profile and Avatar Badges as Menu Triggers
        const headerProfileTrigger = document.getElementById('headerProfileTrigger');
        if (headerProfileTrigger) {
          headerProfileTrigger.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggleSettingsMenu(e);
          });
        }

        const headerAvatarBadge = document.getElementById('headerAvatarBadge');
        if (headerAvatarBadge) {
          headerAvatarBadge.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggleSettingsMenu(e);
          });
        }

        // Prevent clicks inside the dropdown from closing it prematurely
        const settingsDropdown = document.getElementById('settingsDropdown');
        if (settingsDropdown) {
          settingsDropdown.addEventListener('click', (e) => {
            e.stopPropagation();
          });
        }

        // 3. Settings Dropdown Items
        const profileBtn = document.querySelector('.settings-dropdown-item:not(.settings-item-danger)');
        if (profileBtn) {
          profileBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openProfileView('accountInfo');
          });
        }

        // 4. Sidebar Mobile Overlay
        const overlay = document.querySelector('.sidebar-overlay');
        if (overlay) {
          overlay.addEventListener('click', (e) => {
            e.preventDefault();
            closeSidebarMobile();
          });
        }

        // 5. Sidebar Navigation Menu Items
        document.querySelectorAll('.sidebar-nav-item').forEach(item => {
          item.addEventListener('click', function(e) {
            closeSettingsMenu();
          });
        });

        // 6. Global outside click handler to dismiss settings dropdown
        document.addEventListener('click', (e) => {
          if (!e.target.closest('#settingsToggleBtn') && 
              !e.target.closest('#settingsDropdown') && 
              !e.target.closest('#headerProfileTrigger') && 
              !e.target.closest('#headerAvatarBadge') &&
              !e.target.closest('#headerNotificationBtn') &&
              !e.target.closest('#notificationDropdown')) {
            closeSettingsMenu();
          }
        });
      } catch (err) {
        console.warn('Sidebar and Settings listener registration notice:', err);
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', setupSidebarAndSettingsListeners);
    } else {
      setupSidebarAndSettingsListeners();
    }

    // =========================================================================
    // MULTI-STEP TENANT ID & LIVE FACE VERIFICATION JAVASCRIPT
    // Strictly ONLY appears and opens when the tenant clicks the "Verify Account" button in Account Information
    // =========================================================================
    let selectedIdFile = null;
    let preprocessedIdBlob = null;
    let preprocessedIdDataUrl = '';
    let heldOcrRawText = '';
    let webcamStream = null;
    let capturedFaceBlob = null;
    let capturedFaceDataUrl = '';
    let isFaceCaptureValid = false;
    let currentVerificationStep = 1;

    // Strict Anti-Spoofing State Machines for Phase 3 Live Testing
    let livenessBlinkState = 'INITIAL'; // 'INITIAL' -> 'OPEN_OBSERVED' -> 'DROPPED_CLOSED' -> 'COMPLETED'
    let livenessHeadState = 'INITIAL';  // 'INITIAL' -> 'CENTER_OBSERVED' -> 'TURNED_AWAY' -> 'COMPLETED'
    let livenessOpenFrames = 0;
    let livenessCenterFrames = 0;
    let livenessTurnDirection = '';

    function resetLivenessAntiSpoofingState() {
      livenessBlinkState = 'INITIAL';
      livenessHeadState = 'INITIAL';
      livenessOpenFrames = 0;
      livenessCenterFrames = 0;
      livenessTurnDirection = '';
    }

    // Image Pre-processing for OCR:
    // Converts attached ID to grayscale, enhances contrast, and applies binarization/thresholding.
    // This maximizes character legibility for the OCR engine and eliminates errors from blurry or low-contrast uploads.
    function preprocessIdImageForOCR(file, onComplete) {
      if (!file || !file.type || !file.type.startsWith('image/')) {
        if (onComplete) onComplete(null, null);
        return;
      }

      const reader = new FileReader();
      reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
          const canvas = document.createElement('canvas');
          let width = img.width;
          let height = img.height;

          // Limit maximum dimension to 1600px for optimal OCR recognition
          const maxDim = 1600;
          if (width > maxDim || height > maxDim) {
            if (width > height) {
              height = Math.round((height * maxDim) / width);
              width = maxDim;
            } else {
              width = Math.round((width * maxDim) / height);
              height = maxDim;
            }
          }

          canvas.width = width;
          canvas.height = height;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, width, height);

          try {
            const imgData = ctx.getImageData(0, 0, width, height);
            const d = imgData.data;

            // Pre-processing parameters:
            // Contrast enhancement factor (-100 to 100, set to 40)
            const contrast = 40;
            const factor = (259 * (contrast + 255)) / (255 * (259 - contrast));
            // Luminance threshold for binarization (0-255)
            const threshold = 135;

            for (let i = 0; i < d.length; i += 4) {
              // 1. Grayscale luminance conversion: 0.299R + 0.587G + 0.114B
              const gray = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
              // 2. Contrast enhancement
              const cVal = factor * (gray - 128) + 128;
              // 3. Binarization thresholding
              const bin = cVal >= threshold ? 255 : 0;

              d[i] = bin;     // R
              d[i + 1] = bin; // G
              d[i + 2] = bin; // B
            }

            ctx.putImageData(imgData, 0, 0);

            preprocessedIdDataUrl = canvas.toDataURL('image/jpeg', 0.92);
            canvas.toBlob(function(blob) {
              preprocessedIdBlob = blob;
              if (onComplete) onComplete(blob, preprocessedIdDataUrl);
            }, 'image/jpeg', 0.92);
          } catch (err) {
            console.warn('Canvas pre-processing warning:', err);
            if (onComplete) onComplete(null, null);
          }
        };
        img.src = e.target.result;
      };
      reader.readAsDataURL(file);
    }

    function openVerifyAccountModal() {
      const modal = document.getElementById('verifyAccountModal');
      if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('open');
        modal.style.pointerEvents = 'auto';
      }
      goToVerificationStep(1);
    }

    function removeIdFile(e) {
      if (e) e.stopPropagation();
      selectedIdFile = null;
      preprocessedIdBlob = null;
      preprocessedIdDataUrl = '';
      heldOcrRawText = '';
      const fileInput = document.getElementById('idFileInput');
      if (fileInput) fileInput.value = '';
      const previewImg = document.getElementById('idVisualPreviewImg');
      if (previewImg) previewImg.src = '';
      const previewCard = document.getElementById('idImagePreviewCard');
      if (previewCard) previewCard.style.display = 'none';
      const dropZone = document.getElementById('idDropZone');
      if (dropZone) dropZone.style.display = 'block';
    }

    function closeVerifyAccountModal() {
      stopLivenessCamera();
      if (typeof resetTenantLivenessState === 'function') {
        resetTenantLivenessState();
      }
      const modal = document.getElementById('verifyAccountModal');
      if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
      }
      const f = document.getElementById('verifyAccountForm');
      if (f) f.reset();
      removeIdFile();
      resetLivenessAntiSpoofingState();
      heldOcrRawText = '';
      isLivenessConfirmed = false;
      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.innerHTML = '';
      }
      goToVerificationStep(1);
    }

    function goToVerificationStep(stepNum) {
      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) {
        alertBox.style.display = 'none';
        alertBox.innerHTML = '';
      }

      const v1 = document.getElementById('verifyStep1View');
      const v2 = document.getElementById('verifyStep2View');

      const p1 = document.getElementById('stepPill1');
      const p2 = document.getElementById('stepPill2');

      function setPillActive(pill, isActive) {
        if (!pill) return;
        const dot = pill.querySelector('span');
        if (isActive) {
          pill.style.background = 'var(--clay-surface)';
          pill.style.color = 'var(--clay-primary-dark)';
          pill.style.boxShadow = 'var(--clay-button-shadow)';
          if (dot) {
            dot.style.background = 'var(--clay-primary)';
            dot.style.color = 'white';
          }
        } else {
          pill.style.background = 'transparent';
          pill.style.color = 'var(--text-muted)';
          pill.style.boxShadow = 'none';
          if (dot) {
            dot.style.background = '#cbd5e1';
            dot.style.color = '#475569';
          }
        }
      }

      if (stepNum === 2) {
        // Validate Step 1 first: ID Type and ID Photo must be selected
        const idTypeEl = document.getElementById('idTypeSelect');
        const idType = idTypeEl ? idTypeEl.value : '';

        if (!idType) {
          showToast('Please select a valid ID type from the dropdown.', 'error');
          if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.innerHTML = '⚠️ Please select a valid ID type before proceeding.';
          }
          return;
        }

        if (!selectedIdFile) {
          showToast('Please upload or attach a photo of your ID.', 'error');
          if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.innerHTML = '⚠️ Please upload a photo of your ID before proceeding.';
          }
          return;
        }

        // Transition directly to Step 2 (Live Testing Interface)
        currentVerificationStep = 2;
        if (v1) v1.style.display = 'none';
        if (v2) v2.style.display = 'block';

        setPillActive(p1, false);
        setPillActive(p2, true);

        // Start dedicated liveness webcam and facial landmark tracking loop
        startLivenessTestingTab();

      } else {
        // Step 1: Upload ID
        currentVerificationStep = 1;
        stopLivenessCamera();

        if (v1) v1.style.display = 'block';
        if (v2) v2.style.display = 'none';

        setPillActive(p1, true);
        setPillActive(p2, false);

        // Seamless "Back" navigation: Preserve selectedIdFile, preview card, and idType
        if (selectedIdFile) {
          const previewCard = document.getElementById('idImagePreviewCard');
          const dropZone = document.getElementById('idDropZone');
          if (previewCard) previewCard.style.display = 'block';
          if (dropZone) dropZone.style.display = 'none';
        }
      }
    }

    // =========================================================================
    // DEDICATED LIVENESS TESTING & AUTO-CAPTURE (VANILLA JS & face-api.js)
    // 1. Monitors camera feed purely for real-time physical movement.
    // 2. Mathematical state-change anti-spoofing condition.
    // 3. Auto-captures the frame at the moment liveness is verified.
    // 4. Applies ctx.scale(-1, 1) mirror effect to the canvas.
    // 5. Automatically submits Base64 frame + ID image to verify_face.php.
    // =========================================================================

    let livenessStream = null;
    let livenessTrackingTimerId = null;
    let isLivenessTrackingActive = false;
    let isLivenessConfirmed = false;
    let isFaceApiModelsLoaded = false;
    let isModelLoadingInProgress = false;

    /**
     * Euclidean distance between two 2D points: dist = sqrt((x1 - x2)^2 + (y1 - y2)^2)
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

    /**
     * Start camera & tracking in Dedicated Liveness Testing Tab
     */
    async function startLivenessTestingTab() {
      pauseLivenessTracking();
      resetLivenessAntiSpoofingState();
      isLivenessConfirmed = false;

      const overlay = document.getElementById('livenessFinalizingOverlay');
      if (overlay) overlay.style.display = 'none';

      updateLivenessStatusBadge('👀', 'Look directly at camera to start', 'normal');

      const video = document.getElementById('livenessVideo');
      if (!video) return;

      // Start webcam stream for liveness feed if not already running
      try {
        if (!livenessStream || !livenessStream.active) {
          livenessStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false
          });
        }
        if (video.srcObject !== livenessStream) {
          video.srcObject = livenessStream;
        }
        await video.play().catch(() => {});
      } catch (err) {
        console.warn('Liveness camera access:', err);
      }

      // Preload models
      await ensureFaceApiModelsLoaded();

      // Start non-blocking tracking loop (150ms interval)
      isLivenessTrackingActive = true;
      scheduleNextLivenessStep();
    }

    // Pause tracking evaluation loop without cutting the active camera stream
    function pauseLivenessTracking() {
      isLivenessTrackingActive = false;
      if (livenessTrackingTimerId) {
        clearTimeout(livenessTrackingTimerId);
        livenessTrackingTimerId = null;
      }
    }

    // Completely stops camera stream and releases webcam hardware on modal exit
    function stopLivenessCamera() {
      pauseLivenessTracking();
      if (livenessStream) {
        try {
          livenessStream.getTracks().forEach(t => t.stop());
        } catch (e) {}
        livenessStream = null;
      }
      const v = document.getElementById('livenessVideo');
      if (v) v.srcObject = null;
    }

    // Backwards-compatible alias for camera cleanup
    function stopLivenessTracking() {
      pauseLivenessTracking();
    }

    function scheduleNextLivenessStep() {
      if (!isLivenessTrackingActive || isLivenessConfirmed) return;

      livenessTrackingTimerId = setTimeout(async () => {
        if (!isLivenessTrackingActive || isLivenessConfirmed) return;
        try {
          await executeLivenessDetectionStep();
        } catch (err) {
          console.error('Liveness step error:', err);
        }
        if (isLivenessTrackingActive && !isLivenessConfirmed) {
          scheduleNextLivenessStep();
        }
      }, 150); // 150ms non-blocking interval
    }

    /**
     * Pure physical motion detection (Blink or Head Turn) using relaxed thresholds & real-time landmark tracking.
     * Strictly does NOT gatekeep with custom pixel variance, brightness, or blurriness heuristics.
     * Mathematical state machine prevents static photo / phone screen spoofing.
     */
    async function executeLivenessDetectionStep() {
      if (!isLivenessTrackingActive || isLivenessConfirmed) return;

      const video = document.getElementById('livenessVideo');
      if (!video || video.readyState < 2 || video.videoWidth === 0 || video.paused || video.ended) {
        return;
      }

      // Optimized Model Configurations: scoreThreshold relaxed to 0.3, inputSize increased to 320 (multiple of 32)
      // Gives the AI sufficient pixel data to reliably track landmarks under standard indoor lighting without false rejections
      let detection = null;
      if (typeof faceapi !== 'undefined' && faceapi.nets.tinyFaceDetector && faceapi.nets.tinyFaceDetector.params) {
        try {
          const detectorOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.3 });
          detection = await faceapi.detectSingleFace(video, detectorOptions).withFaceLandmarks();
        } catch (err) {
          console.warn('faceapi tracking warning:', err);
        }
      }

      // Seamless real-time tracking: Do not display "no face detected" or "image too blurry" errors on the overlay
      if (!detection || !detection.landmarks) {
        updateLivenessStatusBadge('👀', 'Look directly at camera to start', 'challenge');
        return;
      }

      const landmarks = detection.landmarks.positions ||
        (typeof detection.landmarks.getPositions === 'function' ? detection.landmarks.getPositions() : null);

      if (!landmarks || landmarks.length < 68) {
        updateLivenessStatusBadge('👀', 'Look directly at camera to start', 'challenge');
        return;
      }

      // Strict Removal of Custom Gatekeeping:
      // No custom pixel variance, brightness, or Laplacian blur heuristics are applied to block the test.
      // As long as the 68 facial landmarks are plotted, the system immediately proceeds to EAR and Yaw tracking.

      // 1. Calculate Eye Aspect Ratio (EAR) using 68 landmarks
      // Left eye: 36, 37, 38, 39, 40, 41
      const p36 = landmarks[36], p37 = landmarks[37], p38 = landmarks[38];
      const p39 = landmarks[39], p40 = landmarks[40], p41 = landmarks[41];
      const leftVertical1 = euclideanDistance(p37, p41);
      const leftVertical2 = euclideanDistance(p38, p40);
      const leftHorizontal = Math.max(0.001, euclideanDistance(p36, p39));
      const leftEAR = (leftVertical1 + leftVertical2) / (2.0 * leftHorizontal);

      // Right eye: 42, 43, 44, 45, 46, 47
      const p42 = landmarks[42], p43 = landmarks[43], p44 = landmarks[44];
      const p45 = landmarks[45], p46 = landmarks[46], p47 = landmarks[47];
      const rightVertical1 = euclideanDistance(p43, p47);
      const rightVertical2 = euclideanDistance(p44, p46);
      const rightHorizontal = Math.max(0.001, euclideanDistance(p42, p45));
      const rightEAR = (rightVertical1 + rightVertical2) / (2.0 * rightHorizontal);

      const avgEAR = (leftEAR + rightEAR) / 2.0;

      // 2. Calculate Yaw (Head Turn) via Nose-to-Jaw distance ratio
      // Landmark 30 is nose tip, 0 is left jaw, 16 is right jaw
      const noseTip = landmarks[30];
      const leftJaw = landmarks[0];
      const rightJaw = landmarks[16];

      const dLeft = Math.abs(noseTip.x - leftJaw.x);
      const dRight = Math.abs(rightJaw.x - noseTip.x);
      const jawRatio = dLeft / Math.max(0.001, dRight);

      // =========================================================================
      // RELAXED ANTI-SPOOFING STATE MACHINES (STANDARD INDOOR LIGHTING & CAMERAS)
      // =========================================================================

      // --- BLINK STATE MACHINE ---
      // Relaxed thresholds prevent false rejections while verifying genuine motion:
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
      // Relaxed yaw thresholds allow standard gentle head turns without losing tracking:
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
      if (livenessBlinkState === 'COMPLETED' || livenessHeadState === 'COMPLETED') {
        const triggerReason = (livenessBlinkState === 'COMPLETED')
          ? 'Blink cycle confirmed (open -> closed -> open)'
          : 'Head turn cycle confirmed (center -> turned -> center)';
        console.log('Liveness motion confirmed:', triggerReason);

        // Pause evaluation tracking loop, keeping the live camera stream active for snapshot
        pauseLivenessTracking();

        // 1 second of stillness after completing movement to eliminate motion blur and ensure eyes are open
        updateLivenessStatusBadge('📸', 'Movement verified! Hold still for photo...', 'perfect-capturing');

        setTimeout(async () => {
          await captureAndSubmitLiveVerification(triggerReason);
        }, 1000);
      } else {
        // Seamless real-time tracking feedback (No "no face detected" or "blurry" errors)
        if (livenessBlinkState === 'DROPPED_CLOSED') {
          updateLivenessStatusBadge('👀', 'Eyes closed detected! Re-open eyes...', 'challenge');
        } else if (livenessHeadState === 'TURNED_AWAY') {
          updateLivenessStatusBadge('🔄', 'Head turn detected! Return to center...', 'challenge');
        } else if (livenessBlinkState === 'OPEN_OBSERVED' || livenessHeadState === 'CENTER_OBSERVED') {
          updateLivenessStatusBadge('👀', 'Blink your eyes or turn your head', 'challenge');
        } else {
          updateLivenessStatusBadge('👀', 'Look directly at camera to start', 'challenge');
        }
      }
    }

    /**
     * Auto-Capture on Liveness Detection & Submit Unified Verification
     * Pauses the video feed, draws current frame to canvas with ctx.scale(-1, 1),
     * and submits Base64 frame + ID image to Face++ via verify_face.php.
     */
    async function captureAndSubmitLiveVerification(reason) {
      if (isLivenessConfirmed) return;
      isLivenessConfirmed = true;

      const video = document.getElementById('livenessVideo');
      const canvas = document.getElementById('livenessCaptureCanvas');
      if (!video || !canvas) {
        isLivenessConfirmed = false;
        return;
      }

      // 1. Draw current active frame to hidden canvas maintaining visual mirror effect
      const width = (video.videoWidth && video.videoWidth > 0) ? video.videoWidth : 640;
      const height = (video.videoHeight && video.videoHeight > 0) ? video.videoHeight : 480;
      canvas.width = width;
      canvas.height = height;

      const ctx = canvas.getContext('2d');
      ctx.save();
      ctx.scale(-1, 1);
      ctx.drawImage(video, -width, 0, width, height);
      ctx.restore();

      // Extract high quality Base64 JPEG frame
      const liveFaceBase64 = canvas.toDataURL('image/jpeg', 0.95);

      // 2. Pause video feed only after frame has been cleanly drawn to canvas
      try {
        video.pause();
      } catch (e) {}

      // 3. Show finalizing overlay UI
      const overlay = document.getElementById('livenessFinalizingOverlay');
      const titleEl = document.getElementById('livenessFinalizingTitle');
      const subEl = document.getElementById('livenessFinalizingSub');
      if (overlay) overlay.style.display = 'flex';
      if (titleEl) titleEl.textContent = (reason || 'Action Confirmed') + '!';
      if (subEl) subEl.textContent = 'Comparing face with ID document using Face++...';

      const ring = document.getElementById('livenessOvalRing');
      if (ring) ring.className = 'face-oval-ring perfect-capturing';

      updateLivenessStatusBadge('✨', 'Verifying with Face++...', 'perfect-capturing');

      // 4. Prepare payload with auto-captured Base64 string + Step 1 ID photo
      const idTypeEl = document.getElementById('idTypeSelect');
      const idType = idTypeEl ? idTypeEl.value : '';

      const formData = new FormData();
      formData.append('action', 'complete_verification');
      formData.append('live_face_data', liveFaceBase64);
      if (selectedIdFile) {
        formData.append('id_photo', selectedIdFile);
      }
      if (idType) {
        formData.append('id_type', idType);
      }
      if (heldOcrRawText) {
        formData.append('ocr_raw_text', heldOcrRawText);
      }
      if (preprocessedIdBlob) {
        formData.append('id_photo_processed', preprocessedIdBlob, 'proc_id.jpg');
      }
      formData.append('liveness_passed', '1');
      formData.append('liveness_reason', reason || 'Automated physical movement detection');

      try {
        const response = await fetch('verify_face.php', {
          method: 'POST',
          body: formData
        });
        const result = await response.json();

        if (response.ok && result.status === 'success') {
          // Success! Officially marked as verified
          if (titleEl) titleEl.textContent = 'Verification Successful';
          if (subEl) subEl.textContent = '';

          showToast('Verification Successful', 'success');
          showVerificationAlert(true, 'Verification Successful');

          // Update badges in UI immediately
          const statusBadge = document.getElementById('accountVerificationStatusBadge');
          if (statusBadge) {
            statusBadge.className = 'clay-badge badge-low';
            statusBadge.innerHTML = 'Verified Resident ✓';
          }
          const promptCard = document.getElementById('verificationPromptCard');
          if (promptCard) promptCard.style.display = 'none';

          setTimeout(() => {
            closeVerifyAccountModal();
            window.location.href = 'tenant_portal.php?section=tenantProfile&tab=accountInfo';
          }, 1500);
        } else {
          const errMsg = result.message || 'Face scan does not match the ID photo.';
          showToast(errMsg, 'error');
          if (overlay) overlay.style.display = 'none';

          const alertBox = document.getElementById('verifyModalAlert');
          if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.innerHTML = '⚠️ <strong>Verification Failed:</strong> ' + errMsg;
          }
          // Do not display "no face detected" or "image too blurry" errors on the camera overlay
          const overlayErrMsg = (errMsg.toLowerCase().includes('blurry') || errMsg.toLowerCase().includes('not detected'))
            ? 'Verification mismatch. Retrying...'
            : errMsg;
          updateLivenessStatusBadge('❌', overlayErrMsg, 'spoof');

          setTimeout(() => {
            isLivenessConfirmed = false;
            resetLivenessAntiSpoofingState();
            startLivenessTestingTab();
          }, 2500);
        }
      } catch (err) {
        console.error('Finalize verification error:', err);
        const errMsg = 'Connection error during verification. Please try again.';
        showToast(errMsg, 'error');
        if (overlay) overlay.style.display = 'none';

        const alertBox = document.getElementById('verifyModalAlert');
        if (alertBox) {
          alertBox.style.display = 'block';
          alertBox.innerHTML = '⚠️ <strong>Verification Failed:</strong> ' + errMsg;
        }
        // Do not display "no face detected" or "image too blurry" errors on the camera overlay
        const overlayCatchMsg = (errMsg.toLowerCase().includes('blurry') || errMsg.toLowerCase().includes('not detected'))
          ? 'Verification mismatch. Retrying...'
          : errMsg;
        updateLivenessStatusBadge('❌', overlayCatchMsg, 'spoof');

        setTimeout(() => {
          isLivenessConfirmed = false;
          resetLivenessAntiSpoofingState();
          startLivenessTestingTab();
        }, 2500);
      }
    }

    /**
     * Programmatic / Testing helper for automated verification trigger
     */
    function triggerManualLivenessAction() {
      captureAndSubmitLiveVerification('Automated physical movement detection');
    }

    function updateLivenessStatusBadge(icon, text, mode) {
      const iconEl = document.getElementById('livenessStatusIcon');
      const textEl = document.getElementById('livenessStatusText');
      const pill = document.getElementById('livenessStatusPill');
      const ring = document.getElementById('livenessOvalRing');

      if (iconEl) iconEl.textContent = icon;
      if (textEl) textEl.textContent = text;
      if (pill) {
        pill.className = 'face-status-pill';
        if (mode === 'liveness') pill.classList.add('status-liveness');
        else if (mode === 'holding' || mode === 'perfect-capturing') pill.classList.add('status-holding');
        else if (mode === 'spoof') pill.classList.add('status-spoof');
      }
      if (ring) {
        ring.className = 'face-oval-ring ' + mode;
      }
    }

    // Testing and simulation helpers
    window.euclideanDistance = euclideanDistance;
    window.ensureFaceApiModelsLoaded = ensureFaceApiModelsLoaded;
    window.goToVerificationStep = goToVerificationStep;
    window.startLivenessTestingTab = startLivenessTestingTab;
    window.stopLivenessTracking = stopLivenessTracking;
    window.completeLivenessVerification = captureAndSubmitLiveVerification;
    window.captureAndSubmitLiveVerification = captureAndSubmitLiveVerification;
    window.triggerManualLivenessAction = triggerManualLivenessAction;

    window.__simulateTenantFaceMatch = function(confidence) {
      console.log('Simulating tenant face match with confidence:', confidence || 88.5);
      captureAndSubmitLiveVerification('Simulated match ' + (confidence || 88.5));
    };

    window.__simulateTenantLivenessAction = function(action) {
      console.log('Simulating tenant liveness action:', action);
      captureAndSubmitLiveVerification('Simulated ' + (action || 'blink'));
    };

    window.__simulateLivenessAction = window.__simulateTenantLivenessAction;

    /**
     * Display a verification notification banner inside the Account Information section.
     * Strictly ensures ONLY one single notification bar is rendered in this interface,
     * completely eliminating redundant duplications.
     *
     * - Error/Warning state: styled with visually distinct soft red claymorphism styling.
     * - Success state: displays clean message "Account Successfully Verified".
     * - Display Duration: specifically kept visible inside Account Information for at least 6 seconds (6000ms).
     *
     * @param {boolean} isSuccess
     * @param {string} [title] Optional title or message
     * @param {string} [message] Status message
     */
    function showVerificationAlert(isSuccess, title, message) {
      // 1. Immediately close the verification modal
      closeVerifyAccountModal();

      // 2. Hide any active toast so there is only one unified notification on the screen
      const toastEl = document.getElementById('clayToast');
      if (toastEl) toastEl.classList.remove('show');

      // 3. Keep user on the "Profile -> Account Information" tab
      if (typeof openProfileView === 'function') {
        openProfileView('accountInfo');
      }
      window.scrollTo({ top: 0, behavior: 'smooth' });

      // 4. Resolve clean message
      const displayMsg = isSuccess
        ? 'Verification Successful'
        : (message || title || 'Verification Failed: Please try again');

      // 5. Hide dashboard alert to strictly prevent any duplication
      const dashAlert = document.getElementById('dashboardVerificationAlert');
      if (dashAlert) {
        dashAlert.style.display = 'none';
      }

      // 6. Render ONLY into the single notification bar inside Account Information
      const alertEl = document.getElementById('accountInfoVerificationAlert');
      if (!alertEl) return;

      alertEl.style.opacity = '1';
      alertEl.style.transform = 'translateY(0)';
      alertEl.style.display = 'block';

      if (isSuccess) {
        alertEl.innerHTML = `
          <div class="alert-clay alert-clay-success" style="padding: 16px 22px; border: 2px solid #a7f3d0; background: #ecfdf5; border-radius: var(--radius-xl); box-shadow: var(--clay-card-shadow); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 14px;">
              <div style="width: 40px; height: 40px; border-radius: 12px; background: #d1fae5; color: #059669; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; box-shadow: var(--clay-button-shadow); flex-shrink: 0;">
                ✓
              </div>
              <div>
                <div style="font-size: 15px; font-weight: 800; color: #065f46;">
                  Verification Successful
                </div>
              </div>
            </div>
            <div class="clay-badge" style="background: #10b981; color: #ffffff; font-size: 12px; font-weight: 800; padding: 6px 16px; border-radius: 20px;">
              Verified Resident
            </div>
          </div>
        `;
      } else {
        alertEl.innerHTML = `
          <div class="alert-clay alert-clay-danger" style="padding: 16px 22px; border: 2px solid #fecdd3; background: #fff1f2; border-radius: var(--radius-xl); box-shadow: 6px 6px 16px rgba(225, 29, 72, 0.15), inset 2px 2px 4px rgba(255, 255, 255, 0.8); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 14px; flex: 1;">
              <div style="width: 40px; height: 40px; border-radius: 12px; background: #ffe4e6; color: #e11d48; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; box-shadow: var(--clay-button-shadow); flex-shrink: 0;">
                ✕
              </div>
              <div>
                <div style="font-size: 15px; font-weight: 800; color: #9f1239; margin-bottom: 2px;">
                  Verification Warning
                </div>
                <div style="font-size: 13.5px; font-weight: 600; color: #be123c; line-height: 1.4;">
                  ${displayMsg}
                </div>
              </div>
            </div>
            <button type="button" class="clay-btn clay-btn-primary" style="padding: 9px 20px; font-size: 12.5px; background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 4px 4px 12px rgba(225, 29, 72, 0.35); flex-shrink: 0;" onclick="openVerifyAccountModal()">
              <span>🔄 Verify Account Again</span>
            </button>
          </div>
        `;
      }

      // Clear existing notification timers
      if (window.verificationAlertTimeout) clearTimeout(window.verificationAlertTimeout);
      if (window.verificationFadeTimeout) clearTimeout(window.verificationFadeTimeout);

      // 7. Display Duration: Exactly 6 seconds (6000ms) for notification bar inside Account Information
      const displayDuration = 6000;
      window.verificationAlertTimeout = setTimeout(() => {
        alertEl.style.opacity = '0';
        alertEl.style.transform = 'translateY(-8px)';

        window.verificationFadeTimeout = setTimeout(() => {
          alertEl.style.display = 'none';
          alertEl.style.opacity = '1';
          alertEl.style.transform = 'none';
          if (isSuccess) {
            location.reload();
          }
        }, 500); // 500ms smooth fadeout
      }, displayDuration);
    }

    function showDashboardVerificationAlert(isSuccess, title, message) {
      // 1. Immediately close the verification modal
      closeVerifyAccountModal();

      // 2. Hide active toast
      const toastEl = document.getElementById('clayToast');
      if (toastEl) toastEl.classList.remove('show');

      // 3. Direct user to dashboard overview
      if (typeof switchTenantSection === 'function') {
        const dashboardBtn = document.querySelectorAll('.sidebar-nav-item')[0];
        switchTenantSection('tenantOverview', dashboardBtn);
      }
      window.scrollTo({ top: 0, behavior: 'smooth' });

      // 4. Hide account info alert to strictly prevent any duplication
      const accAlert = document.getElementById('accountInfoVerificationAlert');
      if (accAlert) accAlert.style.display = 'none';

      // 5. Render ONLY into dashboard container
      const alertEl = document.getElementById('dashboardVerificationAlert');
      if (!alertEl) return;

      const displayMsg = isSuccess
        ? 'Verification Successful'
        : (message || title || 'Verification Failed: Please try again');

      alertEl.style.opacity = '1';
      alertEl.style.transform = 'translateY(0)';
      alertEl.style.display = 'block';

      if (isSuccess) {
        alertEl.innerHTML = `
          <div class="alert-clay alert-clay-success" style="padding: 16px 22px; border: 2px solid #a7f3d0; background: #ecfdf5; border-radius: var(--radius-xl); box-shadow: var(--clay-card-shadow); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 14px;">
              <div style="width: 40px; height: 40px; border-radius: 12px; background: #d1fae5; color: #059669; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; box-shadow: var(--clay-button-shadow); flex-shrink: 0;">
                ✓
              </div>
              <div>
                <div style="font-size: 15px; font-weight: 800; color: #065f46;">
                  Verification Successful
                </div>
              </div>
            </div>
            <div class="clay-badge" style="background: #10b981; color: #ffffff; font-size: 12px; font-weight: 800; padding: 6px 16px; border-radius: 20px;">
              Verified Resident
            </div>
          </div>
        `;
      } else {
        alertEl.innerHTML = `
          <div class="alert-clay alert-clay-danger" style="padding: 16px 22px; border: 2px solid #fecdd3; background: #fff1f2; border-radius: var(--radius-xl); box-shadow: 6px 6px 16px rgba(225, 29, 72, 0.15), inset 2px 2px 4px rgba(255, 255, 255, 0.8); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 14px; flex: 1;">
              <div style="width: 40px; height: 40px; border-radius: 12px; background: #ffe4e6; color: #e11d48; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; box-shadow: var(--clay-button-shadow); flex-shrink: 0;">
                ✕
              </div>
              <div>
                <div style="font-size: 15px; font-weight: 800; color: #9f1239; margin-bottom: 2px;">
                  Verification Warning
                </div>
                <div style="font-size: 13.5px; font-weight: 600; color: #be123c; line-height: 1.4;">
                  ${displayMsg}
                </div>
              </div>
            </div>
            <button type="button" class="clay-btn clay-btn-primary" style="padding: 9px 20px; font-size: 12.5px; background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 4px 4px 12px rgba(225, 29, 72, 0.35); flex-shrink: 0;" onclick="openVerifyAccountModal()">
              <span>🔄 Verify Account Again</span>
            </button>
          </div>
        `;
      }

      if (window.dashboardVerificationAlertTimeout) clearTimeout(window.dashboardVerificationAlertTimeout);
      if (window.dashboardVerificationFadeTimeout) clearTimeout(window.dashboardVerificationFadeTimeout);

      window.dashboardVerificationAlertTimeout = setTimeout(() => {
        alertEl.style.opacity = '0';
        alertEl.style.transform = 'translateY(-8px)';

        window.dashboardVerificationFadeTimeout = setTimeout(() => {
          alertEl.style.display = 'none';
          alertEl.style.opacity = '1';
          alertEl.style.transform = 'none';
          if (isSuccess) {
            location.reload();
          }
        }, 500);
      }, 6000);
    }

    async function submitFullVerification() {
      const idTypeEl = document.getElementById('idTypeSelect');
      const idType = idTypeEl ? idTypeEl.value : '';
      const alertBox = document.getElementById('verifyModalAlert');
      if (alertBox) alertBox.style.display = 'none';

      if (!idType) {
        showToast('Please select your ID type in Step 1.', 'error');
        goToVerificationStep(1);
        return;
      }

      if (!selectedIdFile) {
        showToast('Please upload an ID image in Step 1.', 'error');
        goToVerificationStep(1);
        return;
      }

      if (!capturedFaceDataUrl && !capturedFaceBlob) {
        showToast('Please capture your live face scan before submitting.', 'error');
        if (alertBox) {
          alertBox.style.display = 'block';
          alertBox.innerHTML = '⚠️ Please click <strong>Capture Face</strong> to take your live selfie photo first.';
        }
        return;
      }

      const btn = document.getElementById('btnSubmitVerification');
      const btnBack = document.getElementById('btnBackToStep1');
      const origText = btn ? btn.innerHTML : 'Submit &amp; Verify Account';

      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>Scanning ID Document (OCR)...</span>';
      }
      if (btnBack) btnBack.disabled = true;

      // Extract raw text from the uploaded ID using client-side Tesseract.js OCR
      let ocrRawText = '';
      if (window.Tesseract && typeof Tesseract.recognize === 'function') {
        try {
          const targetImage = preprocessedIdBlob || selectedIdFile;
          const ocrPromise = Tesseract.recognize(targetImage, 'eng');
          const timeoutPromise = new Promise((_, reject) => setTimeout(() => reject(new Error('OCR Timeout')), 10000));
          const ocrResult = await Promise.race([ocrPromise, timeoutPromise]);
          if (ocrResult && ocrResult.data && ocrResult.data.text) {
            ocrRawText = ocrResult.data.text;
          }
        } catch (ocrErr) {
          console.warn('Client OCR extraction notice:', ocrErr);
        }
      }

      if (btn) {
        btn.innerHTML = '<span>Verifying ID &amp; Face Biometrics...</span>';
      }

      const formData = new FormData();
      formData.append('action', 'verify_account');
      formData.append('id_type', idType);
      formData.append('id_photo', selectedIdFile);
      if (ocrRawText) {
        formData.append('ocr_raw_text', ocrRawText);
      }

      // Pass client-side pre-processed image (Grayscale + Contrast + Binarization) for high-accuracy OCR
      if (preprocessedIdBlob) {
        formData.append('id_photo_processed', preprocessedIdBlob, 'proc_id.jpg');
      }
      if (preprocessedIdDataUrl) {
        formData.append('id_photo_processed_data', preprocessedIdDataUrl);
      }

      if (capturedFaceBlob) {
        formData.append('live_face', capturedFaceBlob, 'live_face.jpg');
      }
      if (capturedFaceDataUrl) {
        formData.append('live_face_data', capturedFaceDataUrl);
      }

      try {
        const res = await fetch('verify_face.php', { method: 'POST', body: formData });
        const result = await res.json();

        // 1. Immediately close the verification modal/tab upon completion
        closeVerifyAccountModal();

        if (res.ok && result.status === 'success') {
          // Simple, clean success message visible for 6 seconds
          showVerificationAlert(true, 'Verification Successful');
          showToast('Verification Successful', 'success');
          setTimeout(() => {
            window.location.href = 'tenant_portal.php?section=tenantProfile&tab=accountInfo';
          }, 1500);
        } else {
          const errMsg = result.message || 'Face scan does not match the ID photo.';
          showVerificationAlert(false, errMsg);
          showToast(errMsg, 'error');
        }
      } catch (err) {
        console.error('Verification error:', err);
        // Immediately close the verification modal/tab upon completion
        closeVerifyAccountModal();
        showVerificationAlert(false, 'Face scan does not match the ID photo.');
      } finally {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = origText;
        }
        if (btnBack) btnBack.disabled = false;
      }
    }

    function handleIdFile(file) {
      if (file.size > 10 * 1024 * 1024) {
        showToast('ID file exceeds 10MB limit', 'error');
        return;
      }
      selectedIdFile = file;
      preprocessedIdBlob = null;
      preprocessedIdDataUrl = '';
      heldOcrRawText = '';

      // Immediately pre-process image in the background (Grayscale + Contrast + Binarization) for high-accuracy OCR
      preprocessIdImageForOCR(file, function(blob, dataUrl) {
        if (window.Tesseract && typeof Tesseract.recognize === 'function') {
          Tesseract.recognize(blob || file, 'eng').then(res => {
            if (res && res.data && res.data.text) {
              heldOcrRawText = res.data.text;
            }
          }).catch(err => {
            console.warn('Background OCR notice:', err);
          });
        }
      });

      const nameEl = document.getElementById('idPreviewFileName');
      const sizeEl = document.getElementById('idPreviewFileSize');
      if (nameEl) nameEl.textContent = file.name;
      if (sizeEl) sizeEl.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';

      // Visual Image Preview (Vanilla JS FileReader) so tenant can confirm what they uploaded before clicking "Next"
      const previewImg = document.getElementById('idVisualPreviewImg');
      const previewCard = document.getElementById('idImagePreviewCard');
      const dropZone = document.getElementById('idDropZone');

      if (file.type && file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
          if (previewImg) previewImg.src = e.target.result;
          if (previewCard) previewCard.style.display = 'block';
          if (dropZone) dropZone.style.display = 'none';
        };
        reader.readAsDataURL(file);
      } else {
        if (previewCard) previewCard.style.display = 'block';
        if (dropZone) dropZone.style.display = 'none';
      }

      showToast('ID attached: ' + file.name, 'info');
    }

    function handleFile(file) {
      if (file.size > 8 * 1024 * 1024) {
        showToast('Receipt exceeds 8MB limit', 'error');
        return;
      }
      attachedFile = file;
      const previewName = document.getElementById('previewFileName');
      const previewSize = document.getElementById('previewFileSize');
      const previewCard = document.getElementById('filePreviewCard');
      if (previewName) previewName.textContent = file.name;
      if (previewSize) previewSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
      if (previewCard) previewCard.style.display = 'flex';
      showToast('Receipt attached: ' + file.name, 'info');
    }

    // Comprehensive Event Listener Initialization (Guarded against blocking errors)
    function initTenantEventListeners() {
      // 1. Verify Account Modal Backdrop Click-to-Close
      try {
        const modal = document.getElementById('verifyAccountModal');
        if (modal) {
          modal.addEventListener('click', (e) => {
            if (e.target === modal) {
              closeVerifyAccountModal();
            }
          });
        }
      } catch (e) {
        console.warn('Modal backdrop listener notice:', e);
      }

      // 2. Sidebar Navigation Items & Toggle Controls
      try {
        const sidebarToggle = document.getElementById('sidebarToggleBtn');
        if (sidebarToggle) {
          sidebarToggle.addEventListener('click', (e) => {
            e.preventDefault();
            toggleSidebar();
          });
        }

        const settingsToggle = document.getElementById('settingsToggleBtn');
        if (settingsToggle) {
          settingsToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleSettingsMenu();
          });
        }
      } catch (e) {
        console.warn('Sidebar controls listener notice:', e);
      }

      // 3. Drop Zone & File Selection for ID Verification
      try {
        const idDropZone = document.getElementById('idDropZone');
        const idFileInput = document.getElementById('idFileInput');

        if (idDropZone && idFileInput) {
          idDropZone.addEventListener('click', (e) => {
            if (e.target !== document.getElementById('btnRemoveIdFile')) idFileInput.click();
          });

          ['dragenter', 'dragover'].forEach(n => idDropZone.addEventListener(n, (e) => {
            e.preventDefault();
            idDropZone.classList.add('drag-over');
          }));

          ['dragleave', 'drop'].forEach(n => idDropZone.addEventListener(n, (e) => {
            e.preventDefault();
            idDropZone.classList.remove('drag-over');
          }));

          idDropZone.addEventListener('drop', (e) => {
            if (e.dataTransfer && e.dataTransfer.files.length > 0) handleIdFile(e.dataTransfer.files[0]);
          });

          idFileInput.addEventListener('change', () => {
            if (idFileInput.files && idFileInput.files.length > 0) handleIdFile(idFileInput.files[0]);
          });
        }
      } catch (e) {
        console.warn('ID dropzone listener notice:', e);
      }

      // 4. Change Password Form Submission
      try {
        const passForm = document.getElementById('changePasswordForm');
        if (passForm) {
          passForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const currentPassEl = document.getElementById('profCurrentPass');
            const newPassEl = document.getElementById('profNewPass');
            const confirmPassEl = document.getElementById('profConfirmPass');
            const currentPassword = currentPassEl ? currentPassEl.value : '';
            const newPassword = newPassEl ? newPassEl.value : '';
            const confirmPassword = confirmPassEl ? confirmPassEl.value : '';

            if (newPassword !== confirmPassword) {
              showToast('New passwords do not match.', 'error');
              return;
            }

            if (newPassword.length < 6) {
              showToast('Password must be at least 6 characters.', 'error');
              return;
            }

            const btn = document.getElementById('btnSavePassword');
            if (btn) {
              btn.disabled = true;
              btn.textContent = 'Updating...';
            }

            try {
              const res = await fetch('api/profile_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  action: 'change_password',
                  current_password: currentPassword,
                  new_password: newPassword,
                  confirm_password: confirmPassword
                })
              });

              const data = await res.json();
              if (res.ok && data.status === 'success') {
                showToast(data.message, 'success');
                passForm.reset();
                // Contextual Routing: Refresh and keep tenant on the Profile -> Change Password page
                setTimeout(() => {
                  window.location.href = 'tenant_portal.php?section=tenantProfile&tab=changePassword';
                }, 1000);
              } else {
                showToast(data.message || 'Error updating password', 'error');
              }
            } catch (err) {
              showToast('Network error updating password', 'error');
            } finally {
              if (btn) {
                btn.disabled = false;
                btn.textContent = 'Update Password';
              }
            }
          });
        }
      } catch (e) {
        console.warn('Password form listener notice:', e);
      }

      // 5. Edit Account Information Form Submission
      try {
        const editInfoForm = document.getElementById('editAccountInfoForm');
        if (editInfoForm) {
          editInfoForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btnSaveAccountInfo');
            if (btn) {
              btn.disabled = true;
              btn.textContent = 'Saving...';
            }

            const payload = {
              action: 'update_account_info',
              email: document.getElementById('editEmail').value.trim(),
              phone: document.getElementById('editPhone').value.trim(),
              emergency_contact_name: document.getElementById('editEmergencyName').value.trim(),
              emergency_contact_relationship: document.getElementById('editEmergencyRel').value.trim(),
              emergency_contact_phone: document.getElementById('editEmergencyPhone').value.trim()
            };

            try {
              const res = await fetch('api/profile_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
              });
              const result = await res.json();
              if (res.ok && result.status === 'success') {
                showToast(result.message, 'success');
                closeEditAccountInfoModal();
                // Contextual Routing: Refresh and keep tenant on the Profile -> Account Information page
                setTimeout(() => {
                  window.location.href = 'tenant_portal.php?section=tenantProfile&tab=accountInfo';
                }, 1000);
              } else {
                showToast(result.message || 'Error updating account information', 'error');
              }
            } catch (err) {
              showToast('Network error updating account information', 'error');
            } finally {
              if (btn) {
                btn.disabled = false;
                btn.textContent = 'Save Information';
              }
            }
          });
        }
      } catch (e) {
        console.warn('Edit account info form listener notice:', e);
      }

      // 6. Priority Segmented Pills
      try {
        document.querySelectorAll('.priority-pill').forEach(btn => {
          btn.addEventListener('click', (e) => {
            e.preventDefault();
            document.querySelectorAll('.priority-pill').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentPrio = btn.getAttribute('data-prio') || 'medium';
          });
        });
      } catch (e) {
        console.warn('Priority pills listener notice:', e);
      }

      // 7. Maintenance Ticket Form Submission & Photo Attachment
      try {
        const maintForm = document.getElementById('maintenanceForm');
        const photoInput = document.getElementById('ticketPhotos');
        const photoPreview = document.getElementById('ticketPhotosPreview');

        if (photoInput && photoPreview) {
          const uploadLabel = document.getElementById('ticketPhotosUploadLabel');
          photoInput.addEventListener('change', function() {
            photoPreview.innerHTML = '';
            if (this.files && this.files.length > 0) {
              if (uploadLabel) {
                uploadLabel.textContent = this.files.length === 1 ? this.files[0].name : (this.files.length + ' Photos Attached');
              }
              photoPreview.style.display = 'flex';
              Array.from(this.files).forEach((file, idx) => {
                if (file.type.startsWith('image/')) {
                  const imgWrapper = document.createElement('div');
                  imgWrapper.style.position = 'relative';
                  imgWrapper.style.display = 'inline-block';

                  const img = document.createElement('img');
                  img.src = URL.createObjectURL(file);
                  img.style.width = '64px';
                  img.style.height = '64px';
                  img.style.objectFit = 'cover';
                  img.style.borderRadius = 'var(--radius-sm)';
                  img.style.border = '1.5px solid var(--clay-border)';
                  img.title = file.name;

                  imgWrapper.appendChild(img);
                  photoPreview.appendChild(imgWrapper);
                }
              });
            } else {
              if (uploadLabel) {
                uploadLabel.textContent = 'Attach Photos of Item';
              }
              photoPreview.style.display = 'none';
            }
          });
        }

        if (maintForm) {
          maintForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const titleEl = document.getElementById('ticketTitle');
            const descEl = document.getElementById('ticketDesc');
            const catEl = document.getElementById('ticketCategory');
            const title = titleEl ? titleEl.value.trim() : '';
            const desc = descEl ? descEl.value.trim() : '';
            const cat = catEl ? catEl.value : 'General';

            const btn = document.getElementById('btnLogTicket');
            if (btn) {
              btn.disabled = true;
              btn.innerHTML = '<span>Logging...</span>';
            }

            try {
              const formData = new FormData();
              formData.append('title', title);
              formData.append('description', desc);
              formData.append('category', cat);
              formData.append('priority', currentPrio);

              if (photoInput && photoInput.files && photoInput.files.length > 0) {
                for (let i = 0; i < photoInput.files.length; i++) {
                  formData.append('maintenance_photos[]', photoInput.files[i]);
                }
              }

              const res = await fetch('api/ticket_api.php', {
                method: 'POST',
                body: formData
              });
              const result = await res.json();
              if (res.ok && result.status === 'success') {
                showToast(result.message);
                maintForm.reset();
                if (photoPreview) {
                  photoPreview.innerHTML = '';
                  photoPreview.style.display = 'none';
                }
                // Contextual Page Routing: Explicit Exception - Automatically redirect away from submission form straight back to Tenant Dashboard
                setTimeout(() => {
                  window.location.href = 'tenant_portal.php?section=tenantOverview';
                }, 800);
              } else {
                showToast(result.message || 'Error logging ticket', 'error');
              }
            } catch (err) {
              showToast('Network error submitting ticket', 'error');
            } finally {
              if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<span>🛠️ Dispatch Maintenance Ticket</span>';
              }
            }
          });
        }
      } catch (e) {
        console.warn('Maintenance form listener notice:', e);
      }

      // 8. Payment Drop-Zone & Receipt Upload Submission
      try {
        const dropZone = document.getElementById('receiptDropZone');
        const fileInput = document.getElementById('receiptFileInput');
        const previewCard = document.getElementById('filePreviewCard');
        const btnRemoveFile = document.getElementById('btnRemoveFile');
        const btnUploadReceipt = document.getElementById('btnUploadReceipt');

        if (dropZone && fileInput) {
          dropZone.addEventListener('click', (e) => {
            if (e.target !== btnRemoveFile) fileInput.click();
          });

          ['dragenter', 'dragover'].forEach(n => dropZone.addEventListener(n, (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
          }));

          ['dragleave', 'drop'].forEach(n => dropZone.addEventListener(n, (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
          }));

          dropZone.addEventListener('drop', (e) => {
            if (e.dataTransfer && e.dataTransfer.files.length > 0) handleFile(e.dataTransfer.files[0]);
          });

          fileInput.addEventListener('change', () => {
            if (fileInput.files && fileInput.files.length > 0) handleFile(fileInput.files[0]);
          });
        }

        if (btnRemoveFile) {
          btnRemoveFile.addEventListener('click', (e) => {
            e.stopPropagation();
            attachedFile = null;
            if (fileInput) fileInput.value = '';
            if (previewCard) previewCard.style.display = 'none';
          });
        }

        if (btnUploadReceipt) {
          btnUploadReceipt.addEventListener('click', async () => {
            if (!attachedFile) {
              showToast('Please attach or drop a payment receipt first.', 'error');
              return;
            }

            btnUploadReceipt.disabled = true;
            btnUploadReceipt.innerHTML = '<span>Uploading Proof...</span>';

            const progressWrapper = document.getElementById('progressWrapper');
            const progressFill = document.getElementById('progressFill');
            if (progressWrapper) progressWrapper.style.display = 'block';

            let p = 0;
            const interval = setInterval(() => {
              p += 25;
              if (progressFill) progressFill.style.width = p + '%';
              if (p >= 100) clearInterval(interval);
            }, 100);

            const payCycleEl = document.getElementById('payCycle');
            const payAmountEl = document.getElementById('payAmount');
            const formData = new FormData();
            formData.append('receipt', attachedFile);
            formData.append('billing_cycle', payCycleEl ? payCycleEl.value : '2026-10-01');
            formData.append('amount_paid', payAmountEl ? payAmountEl.value : '0');

            try {
              const res = await fetch('api/payment_api.php', { method: 'POST', body: formData });
              const result = await res.json();
              if (res.ok && result.status === 'success') {
                showToast(result.message);
                // Contextual Page Routing: Explicit Exception - Automatically redirect away from submission form straight back to Tenant Dashboard
                setTimeout(() => {
                  window.location.href = 'tenant_portal.php?section=tenantOverview';
                }, 1000);
              } else {
                showToast(result.message || 'Error uploading receipt', 'error');
                btnUploadReceipt.disabled = false;
                btnUploadReceipt.innerHTML = '<span>📤 Submit Rent Payment Proof</span>';
              }
            } catch (err) {
              showToast('Network error uploading receipt', 'error');
              btnUploadReceipt.disabled = false;
              btnUploadReceipt.innerHTML = '<span>📤 Submit Rent Payment Proof</span>';
            }
          });
        }
      } catch (e) {
        console.warn('Payment receipt dropzone listener notice:', e);
      }

      // Universal Contextual Routing: Restore active section and tab on page load
      const urlParams = new URLSearchParams(window.location.search);
      const targetSec = urlParams.get('section');
      const targetTab = urlParams.get('tab') || 'accountInfo';

      // Initialize Settings button visibility based on whether Profile section is active
      updateProfileSettingsVisibility(targetSec === 'tenantProfile');

      if (targetSec === 'tenantProfile') {
        openProfileView(targetTab, false);
      } else if (targetSec) {
        const targetBtn = document.querySelector(`.sidebar-nav-item[onclick*="${targetSec}"]`);
        switchTenantSection(targetSec, targetBtn, false);
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initTenantEventListeners);
    } else {
      initTenantEventListeners();
    }
  </script>
  <!-- Real-time Centralized Notification Client Handler -->
  <script src="assets/js/notifications.js"></script>
</body>
</html>
