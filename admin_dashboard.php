<?php
/**
 * RentConnect - Landlord Business Intelligence & Management Dashboard
 * Landlord: Lead Dimaculangan (Role: Landlord)
 * Property Architecture: 1 Apartment with Exactly 3 Units (Unlimited Tenants per Unit)
 * Currency: ₱
 */

declare(strict_types=1);

// Prevent browser caching so Back button cannot display protected dashboard after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

require_once __DIR__ . '/config/auth.php';
Auth::requireLandlord();

$pdo = Database::getConnection();

// Fetch current authenticated landlord user
$user = Auth::user();
if ($user && isset($user['id'])) {
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $user['id']]);
    $freshUser = $userStmt->fetch();
    if ($freshUser) {
        $user = $freshUser;
    }
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

// Synchronize and auto-archive any moved-out tenants whose bills are fully settled
require_once __DIR__ . '/includes/tenant_service.php';
TenantService::syncMovedOutSettlements($pdo);

// Fetch 3 Units
$unitsStmt = $pdo->query("SELECT * FROM units ORDER BY unit_number ASC");
$units = $unitsStmt->fetchAll();

// Fetch All Active Tenants (Strictly active, non-archived residents)
$tenantsStmt = $pdo->query("
    SELECT u.id, u.unit_id, u.username, u.name, u.email, u.phone, 
           u.verification_status, u.id_type, u.id_photo_filename, u.verification_notes, u.id_submitted_at,
           u.birthday, u.emergency_contact, u.emergency_contact_name,
           u.emergency_contact_relationship, u.emergency_contact_phone,
           u.move_in_date, u.move_out_date, u.rental_status, u.total_occupants, u.adults_count, u.kids_count, u.created_at,
           un.unit_number, un.monthly_rent
    FROM users u
    JOIN units un ON u.unit_id = un.id
    WHERE u.role = 'tenant' 
      AND u.is_active = 1
      AND (u.is_archived = 0 OR u.is_archived IS NULL)
      AND u.deleted_at IS NULL
    ORDER BY un.unit_number ASC, u.name ASC
");
$allTenants = $tenantsStmt->fetchAll();

// Group tenants by unit
$unitsWithTenants = [];
foreach ($units as $u) {
    $uId = (int)$u['id'];
    $unitsWithTenants[$uId] = $u;
    $unitsWithTenants[$uId]['tenants'] = [];
}
foreach ($allTenants as $t) {
    $uId = (int)$t['unit_id'];
    if (isset($unitsWithTenants[$uId])) {
        $unitsWithTenants[$uId]['tenants'][] = $t;
    }
}

// Fetch Maintenance Tickets
$tickets = $pdo->query("
    SELECT mt.*, u.name AS tenant_name, u.email AS tenant_email, u.phone AS tenant_phone, un.unit_number
    FROM maintenance_tickets mt
    JOIN users u ON mt.tenant_id = u.id
    JOIN units un ON mt.unit_id = un.id
    ORDER BY mt.created_at DESC
")->fetchAll();

foreach ($tickets as &$tk) {
    $st = strtolower((string)$tk['status']);
    if ($st === 'open' || $st === 'pending') {
        $tk['status'] = 'Pending';
    } elseif ($st === 'in_progress' || $st === 'on-going' || $st === 'ongoing') {
        $tk['status'] = 'On-Going';
    } elseif ($st === 'resolved' || $st === 'closed' || $st === 'done') {
        $tk['status'] = 'Done';
    } else {
        $tk['status'] = 'Pending';
    }
}
unset($tk);

$activeTickets = array_values(array_filter($tickets, fn($t) => in_array($t['status'], ['Pending', 'On-Going'])));
$completedTickets = array_values(array_filter($tickets, fn($t) => $t['status'] === 'Done'));

// Fetch Payments Ledger & Pending Reviews (Strictly active, non-archived tenants)
$payments = $pdo->query("
    SELECT p.*, u.name AS tenant_name, u.username AS tenant_username, u.move_in_date, u.rental_status, un.unit_number
    FROM payments p
    JOIN users u ON p.tenant_id = u.id
    JOIN units un ON p.unit_id = un.id
    WHERE (u.is_archived = 0 OR u.is_archived IS NULL)
      AND u.is_active = 1
      AND u.deleted_at IS NULL
    ORDER BY p.id DESC
")->fetchAll();

// Collect distinct billing years from database payment records for filtering
$availablePaymentYears = [];
foreach ($payments as $p) {
    if (!empty($p['billing_cycle'])) {
        $yr = date('Y', strtotime($p['billing_cycle']));
        if (!in_array($yr, $availablePaymentYears, true)) {
            $availablePaymentYears[] = $yr;
        }
    }
}
$currentYear = date('Y');
if (!in_array($currentYear, $availablePaymentYears, true)) {
    $availablePaymentYears[] = $currentYear;
}
rsort($availablePaymentYears);

// Count pending payment receipts for manual review badge (only those with uploaded receipts)
$pendingReviewPayments = array_values(array_filter($payments, fn($p) => $p['status'] === 'pending' && !empty($p['receipt_filename'])));
$pendingReviewCount = count($pendingReviewPayments);
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

  <!-- Chart.js CDN -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <style>
    .modal-backdrop {
      display: none !important;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(4px);
      z-index: 10000;
      align-items: center;
      justify-content: center;
      padding: 20px;
      pointer-events: none !important;
    }
    .modal-backdrop.open { 
      display: flex !important; 
      pointer-events: auto !important;
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
    }
    @keyframes modalSlide {
      from { transform: translateY(20px) scale(0.96); opacity: 0; }
      to { transform: translateY(0) scale(1); opacity: 1; }
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .clay-spinner {
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 2.5px solid rgba(99, 102, 241, 0.25);
      border-top-color: #4f46e5;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }
    .btn-loading-state {
      opacity: 0.75;
      cursor: not-allowed !important;
      pointer-events: none !important;
    }
    .clickable-payment-row {
      cursor: pointer;
      transition: background-color 0.18s ease, transform 0.15s ease, box-shadow 0.18s ease;
    }
    .clickable-payment-row:hover {
      background-color: rgba(99, 102, 241, 0.06) !important;
    }
    .clickable-payment-row:active {
      background-color: rgba(99, 102, 241, 0.12) !important;
    }

    /* Standardized Sidebar Layout & Stacking */
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

    .clay-btn,
    .priority-pill,
    .sidebar-nav-item,
    .profile-subnav-item,
    .subview-tab-btn,
    .btn-remove-file,
    .btn-inspect-proof,
    .main-content button,
    .main-content .clay-btn,
    .dashboard-header-bar button {
      position: relative;
      z-index: 50;
    }

    .subview-tab-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 20px;
      border-radius: var(--radius-md, 12px);
      font-size: 13.5px;
      font-weight: 700;
      cursor: pointer;
      border: 1.5px solid rgba(166, 180, 204, 0.4);
      background: var(--clay-card-bg, #ffffff);
      color: var(--text-muted, #64748b);
      box-shadow: 2px 2px 5px rgba(166, 180, 204, 0.25), -2px -2px 5px rgba(255, 255, 255, 0.8);
      transition: all 0.2s ease;
    }
    .subview-tab-btn:hover {
      color: #0f172a;
      border-color: rgba(99, 102, 241, 0.5);
    }
    .subview-tab-btn.active {
      background: linear-gradient(135deg, #4f46e5, #4338ca);
      color: #ffffff !important;
      border-color: #4338ca;
      box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
    }
    .subview-tab-btn.active .clay-badge {
      background: rgba(255, 255, 255, 0.25) !important;
      color: #ffffff !important;
    }
    .clickable-ticket-row {
      cursor: pointer !important;
      transition: background-color 0.15s ease, transform 0.15s ease;
    }
    .clickable-ticket-row:hover {
      background: rgba(238, 242, 255, 0.85) !important;
    }

    /* Animated Maintenance Details Modal (Fade-In & Scale-Up Transition) */
    #ticketDetailsModal {
      display: flex !important;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 10000;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity 0.24s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.24s ease;
    }
    #ticketDetailsModal.open,
    #ticketDetailsModal.active {
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
    }
    #ticketDetailsModal .ticket-details-modal-box {
      width: 100%;
      max-width: 680px;
      max-height: 90vh;
      overflow-y: auto;
      background: var(--clay-card-bg, #ffffff);
      border-radius: var(--radius-xl, 20px);
      box-shadow: 0 20px 50px -10px rgba(79, 70, 229, 0.3), 0 10px 25px -5px rgba(15, 23, 42, 0.15);
      border: 1.5px solid rgba(166, 180, 204, 0.35);
      padding: 28px;
      transform: scale(0.92) translateY(14px);
      opacity: 0;
      transition: transform 0.26s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.22s ease;
    }
    #ticketDetailsModal.open .ticket-details-modal-box,
    #ticketDetailsModal.active .ticket-details-modal-box {
      transform: scale(1) translateY(0);
      opacity: 1;
    }

    /* Animated Archived Tenant Details Modal (Fade-In & Scale-Up Transition) */
    #archivedTenantDetailsModal {
      display: flex !important;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 10000;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity 0.24s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.24s ease;
    }
    #archivedTenantDetailsModal.open,
    #archivedTenantDetailsModal.active {
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
    }
    #archivedTenantDetailsModal .archived-modal-box {
      width: 100%;
      max-width: 760px;
      max-height: 90vh;
      overflow-y: auto;
      background: var(--clay-card-bg, #ffffff);
      border-radius: var(--radius-xl, 20px);
      box-shadow: 0 20px 50px -10px rgba(79, 70, 229, 0.3), 0 10px 25px -5px rgba(15, 23, 42, 0.15);
      border: 1.5px solid rgba(166, 180, 204, 0.35);
      padding: 28px;
      transform: scale(0.92) translateY(14px);
      opacity: 0;
      transition: transform 0.26s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.22s ease;
    }
    #archivedTenantDetailsModal.open .archived-modal-box,
    #archivedTenantDetailsModal.active .archived-modal-box {
      transform: scale(1) translateY(0);
      opacity: 1;
    }
  </style>
</head>
<body>

  <!-- Overlay for Mobile Retractable Sidebar -->
  <div class="sidebar-overlay" onclick="closeSidebarMobile()"></div>

  <!-- Full Application Shell with Retractable Claymorphic Sidebar -->
  <div class="app-shell">
    
    <!-- ===================================================================
         RETRACTABLE CLAYMORPHIC SIDEBAR
         (Clean navigation drawer: No duplicate toggle button in sidebar)
         =================================================================== -->
    <aside class="clay-sidebar" id="appSidebar">
      <div>
        <!-- Brand Badge & Inner Sidebar Toggle (Strictly inside sidebar) -->
        <div class="sidebar-top-header">
          <div class="brand-wrapper" style="padding: 0; margin-bottom: 0;">
            <div class="brand-icon-clay" style="width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; background: #ffffff;">
              <img src="assets/images/tararent-logo.png" alt="TaraRent Logo" style="width: 32px; height: 32px; object-fit: contain;">
            </div>
            <div class="hide-on-retract">
              <div style="font-size: 18px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px;">TaraRent</div>
              <div style="font-size: 10.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Management Portal</div>
            </div>
          </div>
          <button type="button" id="sidebarToggleBtn" class="clay-toggle-btn sidebar-inner-toggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar" title="Toggle Sidebar">
            ☰
          </button>
        </div>

        <!-- Sidebar Navigation Menu -->
        <div class="sidebar-menu">
          <div class="sidebar-category-label hide-on-retract">Menu Categories</div>
          
          <!-- Requirement: Prominent, clickable "Landlord Dashboard" link routing to analytics view -->
          <button type="button" class="sidebar-nav-item active" id="navLandlordDashboard" data-section="sectionAnalytics" onclick="switchSection('sectionAnalytics', this)" title="Landlord Dashboard">
            <span class="nav-icon">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
              </svg>
            </span>
            <span class="hide-on-retract">Landlord Dashboard</span>
          </button>

          <!-- Requirement: Sidebar category for Tenant Directory -->
          <button type="button" class="sidebar-nav-item" id="navTenantDirectory" data-section="sectionDirectory" onclick="switchSection('sectionDirectory', this)" title="Tenant Directory">
            <span class="nav-icon">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
              </svg>
            </span>
            <span class="hide-on-retract">Tenant Directory</span>
          </button>

          <button type="button" class="sidebar-nav-item" id="navMaintenanceTickets" data-section="sectionTickets" onclick="switchSection('sectionTickets', this)" title="Maintenance Tickets">
            <span class="nav-icon">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.32l-3.276 3.277a3.004 3.004 0 0 1-2.25 2.25l-3.276-3.276a4.5 4.5 0 0 0-6.32 4.486c.048.58.072 1.193-.092 1.743" />
              </svg>
            </span>
            <span class="hide-on-retract">Maintenance Tickets</span>
          </button>

          <!-- Requirement: Manual Payment Verification & Ledger -->
          <button type="button" class="sidebar-nav-item" id="navPaymentVerification" data-section="sectionPayments" onclick="switchSection('sectionPayments', this)" title="Payment Verification &amp; Ledger">
            <span class="nav-icon">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
              </svg>
            </span>
            <span class="hide-on-retract">Payment Verification</span>
            <?php if ($pendingReviewCount > 0): ?>
              <span class="clay-badge badge-pending hide-on-retract" style="margin-left: auto; font-size: 10px; padding: 2px 7px;">
                <?= $pendingReviewCount ?> Review
              </span>
            <?php endif; ?>
          </button>
        </div>
      </div>
    </aside>

    <!-- ===================================================================
         MAIN CONTENT AREA
         =================================================================== -->
    <main class="main-content">
      
      <!-- =================================================================
           TOP NAVIGATION BAR:
           Upper-right elements: Refresh button, Landlord profile block, and Settings button
           ================================================================= -->
      <header class="top-nav-bar">
        <!-- Upper Right: Refresh Button, Landlord Name & Role Title, Avatar, Settings Button -->
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
                      <img src="<?= $userAvatarUrl ?>" alt="<?= htmlspecialchars($user['name'] ?? 'User') ?>">
                    <?php else: ?>
                      <span><?= htmlspecialchars($userInitial) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="fb-profile-main-info">
                    <span class="fb-profile-name" id="profileDropdownName"><?= htmlspecialchars($user['name'] ?? 'Lead Dimaculangan') ?></span>
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

      <!-- SECTION 1: LANDLORD DASHBOARD (ANALYTICS VIEW) -->
      <section id="sectionAnalytics" class="app-view-section active-section">
        
        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Landlord Business Analytics Dashboard</h1>
            <p>Collection efficiency, maintenance expenses per unit, and diagnostic operational intelligence</p>
          </div>

          <!-- Dashboard Summary: No occupancy fractions. Simple summary widget showing ONLY total residing tenants -->
          <div>
            <div class="tenant-summary-widget" id="tenantSummaryWidget">
              <span class="summary-widget-icon">👥</span>
              <span class="summary-widget-text" id="tenantSummaryText"><?= count($allTenants) ?> Active Residents</span>
            </div>
          </div>
        </div>

        <!-- 4 KPI Summary Cards (Strictly ₱) -->
        <div class="kpi-grid">
          <div class="kpi-card clay-card-interactive">
            <div class="kpi-top">
              <span class="kpi-label">Collection Efficiency</span>
              <div class="kpi-icon kpi-icon-efficiency">📈</div>
            </div>
            <div class="kpi-value" id="kpiEfficiency">87.9%</div>
            <div class="kpi-subtitle"><span class="kpi-trend-positive">↑ 6 Billing Cycles</span> • All Units</div>
          </div>

          <div class="kpi-card clay-card-interactive">
            <div class="kpi-top">
              <span class="kpi-label">Outstanding Balance</span>
              <div class="kpi-icon kpi-icon-overdue">⏳</div>
            </div>
            <div class="kpi-value" id="kpiOutstanding">₱24,000.00</div>
            <div class="kpi-subtitle"><span class="kpi-trend-negative">Uncollected Arrears</span></div>
          </div>

          <div class="kpi-card clay-card-interactive">
            <div class="kpi-top">
              <span class="kpi-label">Gross Revenue Collected</span>
              <div class="kpi-icon kpi-icon-revenue">💵</div>
            </div>
            <div class="kpi-value" id="kpiRevenue">₱174,000.00</div>
            <div class="kpi-subtitle"><span>Verified Inflow</span></div>
          </div>

          <div class="kpi-card clay-card-interactive">
            <div class="kpi-top">
              <span class="kpi-label">Maintenance Costs</span>
              <div class="kpi-icon kpi-icon-cost">🛠️</div>
            </div>
            <div class="kpi-value" id="kpiMaintenance">₱12,600.00</div>
            <div class="kpi-subtitle"><span>6 Logged Work Orders</span></div>
          </div>
        </div>

        <!-- Analytics Charts Grid -->
        <div class="analytics-grid">
          <!-- Widget 1: Revenue & Collection Analytics -->
          <div class="clay-card chart-widget">
            <div class="widget-header">
              <div>
                <h2 class="widget-title">Revenue &amp; Collection Analytics</h2>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                  Monthly billed vs. collected rent &amp; efficiency rate (%)
                </p>
              </div>
              <span class="widget-badge">Collection Timeline</span>
            </div>
            <div class="chart-container">
              <canvas id="chartCollectionEfficiency"></canvas>
            </div>
          </div>

          <!-- Widget 2: Maintenance by Category -->
          <div class="clay-card chart-widget">
            <div class="widget-header">
              <div>
                <h2 class="widget-title">Cost Analysis: Repair Categorization</h2>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                  Maintenance expenditure per trade discipline
                </p>
              </div>
              <span class="widget-badge">Category Spend</span>
            </div>
            <div class="chart-container">
              <canvas id="chartCategoryBreakdown"></canvas>
            </div>
          </div>
        </div>

        <!-- Widget 3: Maintenance Costs Per Unit (Bar Chart) -->
        <div class="clay-card chart-widget" style="margin-bottom: 32px;">
          <div class="widget-header">
            <div>
              <h2 class="widget-title">Maintenance Costs Per Unit</h2>
              <p style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                Historical repair expense breakdown across Unit 101, Unit 102, and Unit 103
              </p>
            </div>
            <span class="widget-badge">Unit Comparison</span>
          </div>
          <div class="chart-container">
            <canvas id="chartUnitMaintenance"></canvas>
          </div>
        </div>

        <!-- Diagnostic Intelligence Box -->
        <div class="clay-card" style="margin-top: 10px;">
          <div class="widget-header">
            <div>
              <h2 class="widget-title">Diagnostic Intelligence &amp; Arrears Root Causes</h2>
              <p style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                Automated root-cause diagnostics on unit expenses and delinquencies
              </p>
            </div>
            <span class="clay-badge badge-urgent">Actionable Insights</span>
          </div>
          <div id="diagnosticAlertsContainer" style="margin-top: 14px;">
            <div class="alert-clay alert-clay-warning">
              <div class="alert-icon-clay">⚡</div>
              <div class="alert-body-clay">
                <h4>Primary Expense Category: HVAC (₱4,800.00)</h4>
                <p>HVAC represents 38.1% of all repair expenditures across the 3 units, driven by the Unit 102 aircon blower resolution.</p>
              </div>
            </div>
            <div class="alert-clay alert-clay-info">
              <div class="alert-icon-clay">📌</div>
              <div class="alert-body-clay">
                <h4>Unit 102 Unpaid Balance: October 2026 (₱18,000.00)</h4>
                <p>Maria Santos has an uncollected balance of ₱18,000.00 for October 2026 (Due: 2026-10-05).</p>
              </div>
            </div>
          </div>
        </div>

      </section>

      <!-- SECTION 2: TENANT DIRECTORY (ACTIVE TENANTS + ARCHIVED TENANTS) -->
      <section id="sectionDirectory" class="app-view-section">

        <!-- DIRECTORY VIEW TABS: ACTIVE TENANTS VS ARCHIVED TENANTS -->
        <div class="directory-view-tabs" style="display: flex; gap: 10px; margin-bottom: 22px;">
          <button type="button" class="subview-tab-btn active" id="btnTabActiveDirectory" onclick="switchDirectoryTab('active')">
            👥 Active Tenants
          </button>
          <button type="button" class="subview-tab-btn" id="btnTabArchivedDirectory" onclick="switchDirectoryTab('archived')">
            📁 Archived Tenants 
            <span id="directoryArchivedCountBadge" class="clay-badge" style="font-size: 11px; padding: 2px 8px; margin-left: 6px; background: #e2e8f0; color: #475569;">0</span>
          </button>
        </div>

        <!-- 1. ACTIVE TENANTS VIEW (Folders + Detail) -->
        <div id="directoryActiveView">
          <!-- FOLDERS VIEW (Default Directory Landing: Unit 101, Unit 102, Unit 103) -->
          <div id="directoryFoldersView">
          <div class="dashboard-header-bar">
            <div class="dashboard-title-group">
              <h1>Tenant Directory Folders</h1>
              <p>Select an apartment unit folder to view assigned residents, contact details, and process move-outs</p>
            </div>
            <!-- Requirement: 'Add New Tenant' button strictly removed from root folder view -->
          </div>

          <!-- 3 Claymorphic Folders Grid: Unit 1, Unit 2, Unit 3 -->
          <div class="clay-folder-grid">
            <?php foreach ($unitsWithTenants as $u): ?>
            <div class="clay-folder-card" onclick="openUnitFolder(<?= $u['id'] ?>)">
              <div class="clay-folder-tab"></div>
              <div class="folder-title" style="display: flex; align-items: center; gap: 8px;">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                </svg>
                <span><?= htmlspecialchars($u['unit_number']) ?></span>
              </div>
              <div class="folder-subtitle">Floor <?= $u['floor_level'] ?> • <?= $u['bedrooms'] ?> Bed / <?= $u['bathrooms'] ?> Bath</div>
              
              <div class="folder-meta-row">
                <span class="clay-badge <?= count($u['tenants']) > 0 ? 'badge-paid' : 'badge-low' ?>" style="font-size: 11.5px;" id="unit-folder-badge-<?= $u['id'] ?>">
                  <?= count($u['tenants']) > 0 ? '🔒 Occupied' : '🟢 Available' ?>
                </span>
                <span style="font-size: 13.5px; font-weight: 800; color: var(--clay-primary-dark);">
                  ₱<?= number_format((float)$u['monthly_rent'], 2) ?>/mo
                </span>
              </div>

            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- FOLDER DETAIL VIEW (Visible when Landlord clicks into a specific unit folder) -->
        <div id="directoryFolderDetailView" style="display: none;">
          <?php foreach ($unitsWithTenants as $u): ?>
          <div class="unit-folder-content" id="unit-detail-folder-<?= $u['id'] ?>" style="display: none;">
            
            <div class="folder-detail-header">
              <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <button type="button" class="btn-back-folders" onclick="closeUnitFolder()">
                  ← Back to Folders
                </button>
                <div>
                  <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                    </svg>
                    <span><?= htmlspecialchars($u['unit_number']) ?></span>
                  </h1>
                  <p style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                    Monthly Rent: <strong>₱<?= number_format((float)$u['monthly_rent'], 2) ?>/mo</strong> • Floor <?= $u['floor_level'] ?> • <?= $u['bedrooms'] ?> Bed / <?= $u['bathrooms'] ?> Bath
                  </p>
                </div>
              </div>
              <!-- Requirement: 'Add Tenant' button accessible inside the specific unit view -->
              <div id="unit-add-tenant-btn-wrapper-<?= $u['id'] ?>">
                <?php if (count($u['tenants']) > 0): ?>
                  <button type="button" class="clay-btn" style="opacity: 0.85; cursor: not-allowed; background: rgba(241, 245, 249, 0.9); color: #64748b; border: 1.5px solid #cbd5e1;" onclick="openAddTenantModal(<?= $u['id'] ?>)" title="Unit is already occupied">
                    <span>🔒 Occupied (1 Tenant Limit)</span>
                  </button>
                <?php else: ?>
                  <button type="button" class="clay-btn clay-btn-primary" onclick="openAddTenantModal(<?= $u['id'] ?>)">
                    <span>➕ Add Tenant to <?= htmlspecialchars($u['unit_number']) ?></span>
                  </button>
                <?php endif; ?>
              </div>
            </div>

            <!-- Requirement: Summary View - Clean, clickable summary list showing ONLY: Name, Username, Birthday, Email, and Phone Number -->
            <div id="unit-tenants-container-<?= $u['id'] ?>" style="margin-top: 20px;">
              <?php if (empty($u['tenants'])): ?>
                <div class="clay-card-sm" id="empty-state-unit-<?= $u['id'] ?>" style="text-align: center; color: var(--text-muted); padding: 54px 24px;">
                  <div style="font-size: 46px; margin-bottom: 12px;">📭</div>
                  <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin-bottom: 6px;">No Tenants Assigned to <?= htmlspecialchars($u['unit_number']) ?></h3>
                  <p style="font-size: 13px; max-width: 440px; margin: 0 auto; line-height: 1.5;">This unit currently has no active residents. Click the <strong>"Add Tenant"</strong> button at the top right to provision a new tenant account.</p>
                </div>
              <?php else: ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                  <span style="font-size: 12.5px; font-weight: 700; color: var(--text-muted);">
                    Registered Residents (<span id="unit-count-text-<?= $u['id'] ?>"><?= count($u['tenants']) ?></span>) • Click any resident row to view complete details
                  </span>
                  <span class="clay-badge badge-paid" style="font-size: 11px;">1 Family per Account</span>
                </div>

                <div class="clay-card" style="padding: 0; overflow: hidden; border-radius: var(--radius-lg);">
                  <div class="table-responsive" style="overflow-x: auto;">
                    <table class="tenant-summary-table">
                      <thead>
                        <tr>
                          <th>Name</th>
                          <th>Username</th>
                          <th>Date of Moving In</th>
                          <th>Birthday</th>
                          <th>Email</th>
                          <th>Phone Number</th>
                        </tr>
                      </thead>
                      <tbody id="unit-table-body-<?= $u['id'] ?>">
                        <?php foreach ($u['tenants'] as $t): ?>
                        <tr class="tenant-summary-row" id="tenant-row-<?= $t['id'] ?>" onclick="openTenantDetailsModal(<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars($u['unit_number']) ?>', <?= $u['id'] ?>)" title="Click to view complete details for <?= htmlspecialchars($t['name']) ?>">
                          <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                              <div style="width: 32px; height: 32px; border-radius: 10px; background: linear-gradient(135deg, #a5b4fc, #818cf8); color: #fff; font-weight: 800; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <?= strtoupper(substr($t['name'], 0, 1)) ?>
                              </div>
                              <div style="font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span><?= htmlspecialchars($t['name']) ?></span>
                                <?php if (($t['rental_status'] ?? 'active') === 'moved_out'): ?>
                                  <span class="clay-badge" style="background: #fef3c7; color: #92400e; font-size: 10px; font-weight: 800; padding: 2px 7px;">MOVED OUT (PENDING SETTLEMENT)</span>
                                <?php endif; ?>
                              </div>
                            </div>
                          </td>
                          <td>
                            <span style="font-weight: 700; color: var(--clay-primary-dark); background: #e0e7ff; padding: 3px 8px; border-radius: 6px; font-size: 12.5px;">
                              @<?= htmlspecialchars($t['username']) ?>
                            </span>
                          </td>
                          <td>
                            <span style="font-weight: 700; color: #0f172a;">
                              <?= !empty($t['move_in_date']) ? date('M j, Y', strtotime($t['move_in_date'])) : '<span style="color:var(--text-light)">N/A</span>' ?>
                            </span>
                          </td>
                          <td>
                            <?= !empty($t['birthday']) ? date('M j, Y', strtotime($t['birthday'])) : '<span style="color:var(--text-light)">N/A</span>' ?>
                          </td>
                          <td>
                            <?= htmlspecialchars($t['email']) ?>
                          </td>
                          <td>
                            <?= htmlspecialchars($t['phone'] ?: 'N/A') ?>
                          </td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endif; ?>
            </div>

          </div>
          <?php endforeach; ?>
        </div> <!-- /#directoryFolderDetailView -->
        </div> <!-- /#directoryActiveView -->

        <!-- 2. ARCHIVED TENANTS VIEW (Integrated inside Tenant Directory) -->
        <div id="directoryArchivedView" style="display: none;">
          <div class="dashboard-header-bar">
            <div class="dashboard-title-group">
              <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0;">Archived Tenant Accounts</h1>
                <span id="archivedTenantCountBadge" class="clay-badge" style="font-size: 12px; padding: 4px 12px; background: #e2e8f0; color: #475569; font-weight: 700;">0 Archived Records</span>
              </div>
              <p style="font-size: 13px; color: var(--text-muted); margin-top: 3px;">Preserved repository of deleted and moved-out tenant records with historical billing, receipts, and maintenance logs.</p>
            </div>
            <div class="action-btn-group" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
              <div style="position: relative; min-width: 280px;">
                <input type="text" id="archivedTenantSearchInput" class="clay-input" placeholder="Search by name, email, or unit..." style="padding-left: 36px; font-size: 13px;" oninput="filterArchivedTenants(this.value)">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 17px; height: 17px; position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
              </div>
              <button type="button" class="clay-btn clay-btn-secondary" onclick="loadArchivedTenants()" title="Refresh Archived Records" style="padding: 9px 14px;">
                <span>🔄 Refresh</span>
              </button>
            </div>
          </div>

          <!-- Archived Notice Banner -->
          <div class="alert-clay alert-clay-info" style="margin-bottom: 22px; padding: 14px 20px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: var(--radius-md);">
            <div class="alert-icon-clay" style="color: #64748b;">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 20px; height: 20px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
              </svg>
            </div>
            <div class="alert-body-clay" style="font-size: 13px; color: #475569; line-height: 1.5;">
              <strong>Data Integrity Guarantee:</strong> Deleting a tenant account preserves all payment receipts, billing history, and maintenance records permanently for accounting and auditing.
            </div>
          </div>

          <!-- Archived Tenants Table Card -->
          <div class="clay-card" style="padding: 0; overflow: hidden; border-radius: var(--radius-lg);">
            <div class="table-responsive" style="overflow-x: auto;">
              <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px;">
                <thead>
                  <tr style="border-bottom: 2px solid rgba(166, 180, 204, 0.3); color: var(--text-muted); font-weight: 700; background: rgba(248, 250, 252, 0.6);">
                    <th style="padding: 14px 16px;">Tenant Name</th>
                    <th style="padding: 14px 16px;">Unit Number</th>
                    <th style="padding: 14px 16px;">Email Address</th>
                    <th style="padding: 14px 16px;">Contact Number</th>
                    <th style="padding: 14px 16px;">Date of Moving In</th>
                    <th style="padding: 14px 16px;">Date Archived</th>
                  </tr>
                </thead>
                <tbody id="archivedTenantsTableBody">
                  <tr>
                    <td colspan="6" style="text-align: center; padding: 36px 14px; color: var(--text-muted); font-size: 13.5px;">
                      <span class="clay-spinner" style="width: 18px; height: 18px; border-width: 2px; vertical-align: middle; margin-right: 8px;"></span>
                      Loading archived tenant accounts...
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div> <!-- /#directoryArchivedView -->

      </section>

      <!-- SECTION 3: MAINTENANCE TICKETS (ACTIVE / ON-GOING VS COMPLETED) -->
      <section id="sectionTickets" class="app-view-section">

        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Maintenance Tickets</h1>
            <p>Track, manage, and dispatch maintenance and repair requests across all units</p>
          </div>
        </div>

        <!-- TICKET SUB-NAVIGATION: ACTIVE / ON-GOING VS COMPLETED -->
        <div class="ticket-view-tabs" style="display: flex; gap: 10px; margin-bottom: 20px;">
            <button type="button" class="subview-tab-btn active" id="btnTabActiveTickets" onclick="switchTicketTab('active')">
              🛠️ Active / On-Going Maintenance 
              <span id="activeTicketCountBadge" class="clay-badge badge-pending" style="font-size: 11.5px; padding: 2px 9px; margin-left: 6px;">
                <?= count($activeTickets) ?>
              </span>
            </button>
            <button type="button" class="subview-tab-btn" id="btnTabCompletedTickets" onclick="switchTicketTab('completed')">
              ✅ Completed Maintenance 
              <span id="completedTicketCountBadge" class="clay-badge badge-done" style="font-size: 11.5px; padding: 2px 9px; margin-left: 6px;">
                <?= count($completedTickets) ?>
              </span>
            </button>
          </div>

          <!-- PANE 1: ACTIVE / ON-GOING MAINTENANCE -->
          <div id="paneActiveTickets" class="clay-card" style="padding: 0; overflow: hidden; border-radius: var(--radius-lg);">
            <div style="overflow-x: auto;">
              <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px;">
                <thead>
                  <tr style="border-bottom: 2px solid rgba(166, 180, 204, 0.3); color: var(--text-muted); font-weight: 700; background: rgba(248, 250, 252, 0.6);">
                    <th style="padding: 14px 16px;">Ticket Number</th>
                    <th style="padding: 14px 16px;">Tenant Name</th>
                    <th style="padding: 14px 16px;">Unit</th>
                    <th style="padding: 14px 16px;">Issue Description</th>
                    <th style="padding: 14px 16px;">Date Submitted</th>
                    <th style="padding: 14px 16px;">Priority</th>
                    <th style="padding: 14px 16px;">Status</th>
                  </tr>
                </thead>
                <tbody id="activeTicketsTableBody">
                  <?php if (empty($activeTickets)): ?>
                  <tr id="emptyActiveTicketsRow">
                    <td colspan="7" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 14px;">
                      <div style="font-size: 32px; margin-bottom: 8px;">🎉</div>
                      <strong>No active or pending maintenance requests.</strong>
                      <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">All reported issues are currently resolved.</div>
                    </td>
                  </tr>
                  <?php else: ?>
                  <?php foreach ($activeTickets as $tk): ?>
                  <tr class="clickable-ticket-row ticket-record-row"
                      id="ticket-row-<?= $tk['id'] ?>"
                      data-ticket-id="<?= $tk['id'] ?>"
                      style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);"
                      onclick="openTicketDetailsModal(<?= htmlspecialchars(json_encode($tk), ENT_QUOTES, 'UTF-8') ?>)"
                      title="Click to view maintenance ticket details">
                    <td style="padding: 14px 16px; font-weight: 700; color: #4338ca; white-space: nowrap;">#MT-<?= str_pad((string)$tk['id'], 4, '0', STR_PAD_LEFT) ?></td>
                    <td style="padding: 14px 16px; font-weight: 600; color: #0f172a; white-space: nowrap;"><?= htmlspecialchars($tk['tenant_name']) ?></td>
                    <td style="padding: 14px 16px; white-space: nowrap;">
                      <span class="clay-badge" style="background: #e0e7ff; color: #4338ca; font-weight: 800; font-size: 11px;">
                        <?= htmlspecialchars($tk['unit_number']) ?>
                      </span>
                    </td>
                    <td style="padding: 14px 16px; max-width: 320px;">
                      <div style="font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($tk['title']) ?>">
                        <?= htmlspecialchars($tk['title']) ?>
                      </div>
                      <?php if (!empty($tk['description'])): ?>
                        <div style="font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;" title="<?= htmlspecialchars($tk['description']) ?>">
                          <?= htmlspecialchars(mb_strimwidth($tk['description'], 0, 60, '...')) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td style="padding: 14px 16px; font-size: 12.5px; color: var(--text-muted); white-space: nowrap;">
                      <?= date('M j, Y', strtotime($tk['created_at'])) ?>
                    </td>
                    <td style="padding: 14px 16px; white-space: nowrap;">
                      <span class="clay-badge badge-<?= strtolower($tk['priority']) ?>" style="font-weight: 700; font-size: 11px; padding: 3px 8px;">
                        <?= ucfirst(strtolower($tk['priority'])) ?>
                      </span>
                    </td>
                    <td style="padding: 14px 16px; white-space: nowrap;">
                      <?php 
                        $stClass = 'badge-pending';
                        if ($tk['status'] === 'Done' || $tk['status'] === 'resolved' || $tk['status'] === 'closed') {
                          $stClass = 'badge-done';
                        } elseif ($tk['status'] === 'On-Going' || $tk['status'] === 'in_progress') {
                          $stClass = 'badge-ongoing';
                        }
                      ?>
                      <span id="ticket-badge-<?= $tk['id'] ?>" class="clay-badge <?= $stClass ?>" style="font-weight: 700; font-size: 11.5px; padding: 4px 10px;">
                        <?= htmlspecialchars($tk['status']) ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- PANE 2: COMPLETED MAINTENANCE -->
          <div id="paneCompletedTickets" class="clay-card" style="padding: 0; overflow: hidden; border-radius: var(--radius-lg); display: none;">
            <div style="overflow-x: auto;">
              <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px;">
                <thead>
                  <tr style="border-bottom: 2px solid rgba(166, 180, 204, 0.3); color: var(--text-muted); font-weight: 700; background: rgba(248, 250, 252, 0.6);">
                    <th style="padding: 14px 16px;">Ticket Number</th>
                    <th style="padding: 14px 16px;">Tenant Name</th>
                    <th style="padding: 14px 16px;">Unit</th>
                    <th style="padding: 14px 16px;">Issue Description</th>
                    <th style="padding: 14px 16px;">Date Submitted</th>
                    <th style="padding: 14px 16px;">Priority</th>
                    <th style="padding: 14px 16px;">Status</th>
                  </tr>
                </thead>
                <tbody id="completedTicketsTableBody">
                  <?php if (empty($completedTickets)): ?>
                  <tr id="emptyCompletedTicketsRow">
                    <td colspan="7" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 14px;">
                      <div style="font-size: 32px; margin-bottom: 8px;">📋</div>
                      <strong>No completed maintenance records yet.</strong>
                      <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Resolved tickets will be archived here for historical review.</div>
                    </td>
                  </tr>
                  <?php else: ?>
                  <?php foreach ($completedTickets as $tk): ?>
                  <tr class="clickable-ticket-row ticket-record-row"
                      id="ticket-row-<?= $tk['id'] ?>"
                      data-ticket-id="<?= $tk['id'] ?>"
                      style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);"
                      onclick="openTicketDetailsModal(<?= htmlspecialchars(json_encode($tk), ENT_QUOTES, 'UTF-8') ?>)"
                      title="Click to view maintenance ticket details">
                    <td style="padding: 14px 16px; font-weight: 700; color: #4338ca; white-space: nowrap;">#MT-<?= str_pad((string)$tk['id'], 4, '0', STR_PAD_LEFT) ?></td>
                    <td style="padding: 14px 16px; font-weight: 600; color: #0f172a; white-space: nowrap;"><?= htmlspecialchars($tk['tenant_name']) ?></td>
                    <td style="padding: 14px 16px; white-space: nowrap;">
                      <span class="clay-badge" style="background: #e0e7ff; color: #4338ca; font-weight: 800; font-size: 11px;">
                        <?= htmlspecialchars($tk['unit_number']) ?>
                      </span>
                    </td>
                    <td style="padding: 14px 16px; max-width: 320px;">
                      <div style="font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($tk['title']) ?>">
                        <?= htmlspecialchars($tk['title']) ?>
                      </div>
                      <?php if (!empty($tk['description'])): ?>
                        <div style="font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;" title="<?= htmlspecialchars($tk['description']) ?>">
                          <?= htmlspecialchars(mb_strimwidth($tk['description'], 0, 60, '...')) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td style="padding: 14px 16px; font-size: 12.5px; color: var(--text-muted); white-space: nowrap;">
                      <?= date('M j, Y', strtotime($tk['created_at'])) ?>
                    </td>
                    <td style="padding: 14px 16px; white-space: nowrap;">
                      <span class="clay-badge badge-<?= strtolower($tk['priority']) ?>" style="font-weight: 700; font-size: 11px; padding: 3px 8px;">
                        <?= ucfirst(strtolower($tk['priority'])) ?>
                      </span>
                    </td>
                    <td style="padding: 14px 16px; white-space: nowrap;">
                      <span id="ticket-badge-<?= $tk['id'] ?>" class="clay-badge badge-done" style="font-weight: 700; font-size: 11.5px; padding: 4px 10px;">
                        <?= htmlspecialchars($tk['status']) ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </section>

      <!-- SECTION 4: MANUAL PAYMENT VERIFICATION & AUDIT -->
      <section id="sectionPayments" class="app-view-section">
        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Rent Payment Verification &amp; Audit</h1>
            <p>Review tenant-submitted payment receipts, inspect proof slips, and manually approve or reject payments</p>
          </div>
          <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <button type="button" class="clay-btn clay-btn-primary" onclick="openAnnouncementModal()" style="font-size: 13px; padding: 8px 18px; display: inline-flex; align-items: center; gap: 6px;">
              📢 Send Rent Due Announcement
            </button>
            <?php if ($pendingReviewCount > 0): ?>
              <span class="clay-badge badge-pending" style="font-size: 13px; padding: 8px 18px;">
                ⏳ <?= $pendingReviewCount ?> Receipt<?= $pendingReviewCount === 1 ? '' : 's' ?> Awaiting Review
              </span>
            <?php else: ?>
              <span class="clay-badge badge-approved" style="font-size: 13px; padding: 8px 18px;">
                ✓ All Receipts Verified
              </span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Pending Review Queue (Displayed when tenant uploads receipts) -->
        <?php if ($pendingReviewCount > 0): ?>
        <div style="margin-bottom: 28px;">
          <div class="widget-header" style="margin-bottom: 16px;">
            <div>
              <h2 class="widget-title" style="font-size: 18px;">⏳ Pending Verification Queue</h2>
              <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">
                Review submitted transaction slips and confirm payment legitimacy
              </p>
            </div>
          </div>

          <div class="payment-verification-queue">
            <?php foreach ($pendingReviewPayments as $pending): ?>
            <div class="verification-card" id="pending-card-<?= $pending['id'] ?>">
              <div>
                <div class="verification-card-header">
                  <div>
                    <span class="clay-badge" style="background: #e0e7ff; color: #4338ca; font-weight: 800; font-size: 11.5px;">
                      <?= htmlspecialchars($pending['unit_number']) ?>
                    </span>
                    <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin-top: 8px; margin-bottom: 2px;">
                      <?= htmlspecialchars($pending['tenant_name']) ?>
                    </h3>
                    <div style="font-size: 12px; color: var(--text-muted);">
                      Billing Cycle: <strong><?= date('F Y', strtotime($pending['billing_cycle'])) ?></strong>
                    </div>
                  </div>
                  <span class="clay-badge badge-pending">PENDING</span>
                </div>

                <div class="verification-card-body">
                  <div style="display: flex; justify-content: space-between; align-items: baseline; padding: 10px 14px; background: var(--clay-input-bg); border-radius: var(--radius-md); box-shadow: var(--clay-sunken-shadow);">
                    <span style="font-size: 12.5px; color: var(--text-muted); font-weight: 600;">Submitted Amount:</span>
                    <span style="font-size: 18px; font-weight: 800; color: #047857;">
                      ₱<?= number_format((float)$pending['amount_paid'], 2) ?>
                    </span>
                  </div>
                  <div style="font-size: 12px; color: var(--text-muted); display: flex; justify-content: space-between; margin-top: 4px;">
                    <span>Due: ₱<?= number_format((float)$pending['amount_due'], 2) ?></span>
                    <span>Method: <?= htmlspecialchars($pending['payment_method'] ?? 'Bank Transfer') ?></span>
                  </div>

                  <?php if ($pending['receipt_filename']): ?>
                    <div style="margin-top: 10px;">
                      <a href="uploads/receipts/<?= htmlspecialchars($pending['receipt_filename']) ?>" target="_blank" class="btn-inspect-proof" style="display: inline-flex; width: 100%; justify-content: center; padding: 8px 12px; box-sizing: border-box;">
                        📄 Inspect Uploaded Proof
                      </a>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="verification-card-actions">
                <button type="button" class="btn-verify-approve" onclick="openPaymentDetailsModal(<?= htmlspecialchars(json_encode($pending), ENT_QUOTES, 'UTF-8') ?>, 'approved')">
                  ✓ Approve
                </button>
                <button type="button" class="btn-verify-reject" onclick="openPaymentDetailsModal(<?= htmlspecialchars(json_encode($pending), ENT_QUOTES, 'UTF-8') ?>, 'rejected')">
                  ✕ Reject
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Full Payments Ledger Table with Filtering Dropdowns & Clickable Detail View -->
        <div class="clay-card">
          <div class="widget-header" style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
            <div>
              <h2 class="widget-title" style="font-size: 18px;">All Invoices &amp; Verified Records</h2>
              <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">
                Complete ledger tracking tenant payments, verification status, and audit notes. Click any row to view complete details.
              </p>
            </div>

            <!-- Month & Year Filtering Dropdowns -->
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
              <div style="display: flex; align-items: center; gap: 6px;">
                <label for="paymentFilterMonth" style="font-size: 12px; font-weight: 700; color: var(--text-muted);">Month:</label>
                <select id="paymentFilterMonth" class="clay-select" onchange="applyPaymentFilters()" style="padding: 7px 12px; font-size: 13px; border-radius: var(--radius-sm); min-width: 130px; font-weight: 600;">
                  <option value="all">All Months</option>
                  <option value="01">January</option>
                  <option value="02">February</option>
                  <option value="03">March</option>
                  <option value="04">April</option>
                  <option value="05">May</option>
                  <option value="06">June</option>
                  <option value="07">July</option>
                  <option value="08">August</option>
                  <option value="09">September</option>
                  <option value="10">October</option>
                  <option value="11">November</option>
                  <option value="12">December</option>
                </select>
              </div>

              <div style="display: flex; align-items: center; gap: 6px;">
                <label for="paymentFilterYear" style="font-size: 12px; font-weight: 700; color: var(--text-muted);">Year:</label>
                <select id="paymentFilterYear" class="clay-select" onchange="applyPaymentFilters()" style="padding: 7px 12px; font-size: 13px; border-radius: var(--radius-sm); min-width: 105px; font-weight: 600;">
                  <option value="all">All Years</option>
                  <?php foreach ($availablePaymentYears as $yr): ?>
                    <option value="<?= htmlspecialchars($yr) ?>"><?= htmlspecialchars($yr) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <button type="button" class="clay-btn clay-btn-secondary" onclick="resetPaymentFilters()" style="padding: 7px 14px; font-size: 12.5px;" title="Reset filters to show all records">
                ↺ Reset
              </button>

              <span id="paymentFilterCountBadge" class="clay-badge" style="background: #e0e7ff; color: #4338ca; font-size: 11.5px; font-weight: 700; padding: 7px 14px;">
                Showing <?= count($payments) ?> Records
              </span>
            </div>
          </div>

          <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px;">
              <thead>
                <tr style="border-bottom: 2px solid rgba(166, 180, 204, 0.3); color: var(--text-muted); font-weight: 700;">
                  <th style="padding: 12px 14px;">Billing Month</th>
                  <th style="padding: 12px 14px;">Type</th>
                  <th style="padding: 12px 14px;">Unit</th>
                  <th style="padding: 12px 14px;">Tenant</th>
                  <th style="padding: 12px 14px;">Amount Due</th>
                  <th style="padding: 12px 14px;">Amount Paid</th>
                  <th style="padding: 12px 14px;">Status</th>
                </tr>
              </thead>
              <tbody id="paymentRecordsTableBody">
                <?php if (empty($payments)): ?>
                <tr id="paymentEmptyStateRow">
                  <td colspan="7" style="text-align: center; padding: 32px 14px; color: var(--text-muted); font-size: 14px;">
                    No payment records found. Adding a resident will automatically generate a placeholder rent record awaiting setup.
                  </td>
                </tr>
                <?php else: ?>
                <?php foreach ($payments as $pm): ?>
                <tr class="clickable-payment-row payment-record-row"
                    id="payment-row-<?= $pm['id'] ?>"
                    data-id="<?= $pm['id'] ?>"
                    data-month="<?= !empty($pm['billing_cycle']) ? date('m', strtotime($pm['billing_cycle'])) : '' ?>"
                    data-year="<?= !empty($pm['billing_cycle']) ? date('Y', strtotime($pm['billing_cycle'])) : '' ?>"
                    data-status="<?= htmlspecialchars($pm['status']) ?>"
                    onclick="openPaymentDetailsModal(<?= htmlspecialchars(json_encode($pm), ENT_QUOTES, 'UTF-8') ?>)"
                    title="Click to view detailed payment verification &amp; audit">
                  <td style="padding: 14px; font-weight: 700;">
                    <?php if (!empty($pm['billing_cycle'])): ?>
                      <?= date('F Y', strtotime($pm['billing_cycle'])) ?>
                    <?php else: ?>
                      <span style="color: #92400e; font-style: italic; background: #fef3c7; padding: 3px 8px; border-radius: 6px; font-size: 11.5px; border: 1px solid rgba(245, 158, 11, 0.25);">To be assigned</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 14px;">
                    <?php if (($pm['payment_type'] ?? 'Rent') === 'Advance Deposit'): ?>
                      <span class="clay-badge" style="background: #ede9fe; color: #6d28d9; font-weight: 800; font-size: 11px; border: 1px solid rgba(109, 40, 217, 0.25);">
                        Advance Deposit
                      </span>
                    <?php else: ?>
                      <span class="clay-badge" style="background: #e0f2fe; color: #0369a1; font-weight: 800; font-size: 11px; border: 1px solid rgba(3, 105, 161, 0.25);">
                        Monthly Rent
                      </span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 14px;">
                    <span class="clay-badge" style="background: #e0e7ff; color: #4338ca; font-weight: 800; font-size: 11.5px;">
                      <?= htmlspecialchars($pm['unit_number']) ?>
                    </span>
                  </td>
                  <td style="padding: 14px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                      <div style="width: 28px; height: 28px; border-radius: 8px; background: linear-gradient(135deg, #a5b4fc, #818cf8); color: #fff; font-weight: 800; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <?= strtoupper(substr($pm['tenant_name'], 0, 1)) ?>
                      </div>
                      <span style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($pm['tenant_name']) ?></span>
                    </div>
                  </td>
                  <td style="padding: 14px; font-weight: 700;">
                    <?php if ($pm['amount_due'] !== null && (float)$pm['amount_due'] > 0): ?>
                      ₱<?= number_format((float)$pm['amount_due'], 2) ?>
                    <?php else: ?>
                      <span style="color: var(--text-muted); font-style: italic; font-size: 12px;">Awaiting configuration</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 14px; font-weight: 700; color: <?= (float)$pm['amount_paid'] > 0 ? '#047857' : 'var(--text-muted)' ?>;">
                    ₱<?= number_format((float)$pm['amount_paid'], 2) ?>
                  </td>
                  <td style="padding: 14px;">
                    <?php if ($pm['status'] === 'pending_setup'): ?>
                      <span class="clay-badge" style="background: #fef3c7; color: #92400e; font-weight: 800; border: 1px solid rgba(245, 158, 11, 0.35); font-size: 10.5px; padding: 4px 10px;">
                        AWAITING CONFIGURATION
                      </span>
                    <?php elseif ($pm['status'] === 'unpaid'): ?>
                      <span class="clay-badge" style="background: #fee2e2; color: #991b1b; font-weight: 800; border: 1px solid rgba(239, 68, 68, 0.35); font-size: 10.5px; padding: 4px 10px;">
                        UNPAID
                      </span>
                    <?php elseif ($pm['status'] === 'pending'): ?>
                      <span class="clay-badge badge-pending" style="font-size: 10.5px; padding: 4px 10px;">
                        PENDING REVIEW
                      </span>
                    <?php elseif ($pm['status'] === 'approved'): ?>
                      <span class="clay-badge badge-approved" style="font-size: 10.5px; padding: 4px 10px;">
                        APPROVED
                      </span>
                    <?php elseif ($pm['status'] === 'rejected'): ?>
                      <span class="clay-badge badge-rejected" style="font-size: 10.5px; padding: 4px 10px;">
                        REJECTED
                      </span>
                    <?php else: ?>
                      <span class="clay-badge badge-<?= strtolower($pm['status']) ?>" style="font-size: 10.5px; padding: 4px 10px;">
                        <?= strtoupper($pm['status']) ?>
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
                <tr id="paymentNoFilterResultsRow" style="display: none;">
                  <td colspan="7" style="text-align: center; padding: 32px 14px; color: var(--text-muted); font-size: 13.5px;">
                    No payment records match the selected month and year filters. <a href="javascript:void(0)" onclick="resetPaymentFilters()" style="color: var(--clay-primary); font-weight: 700; text-decoration: underline;">Reset filters</a>
                  </td>
                </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- SECTION 5: LANDLORD PROFILE (VIEW WITH INTERNAL SUB-SIDEBAR) -->
      <section id="sectionProfile" class="app-view-section">
        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Landlord Profile &amp; Settings</h1>
            <p>Manage your account information and login security credentials.</p>
          </div>
        </div>

        <div class="profile-view-container">
          <!-- Internal Sub-Sidebar with exactly two options -->
          <div class="profile-sub-sidebar">
            <button type="button" class="profile-subnav-item active" id="adminSubnavAccount" onclick="switchProfileSubTab('accountInfo', this)">
              <span class="subnav-icon">👤</span>
              <span>Account Information</span>
            </button>
            <button type="button" class="profile-subnav-item" id="adminSubnavPassword" onclick="switchProfileSubTab('changePassword', this)">
              <span class="subnav-icon">🔒</span>
              <span>Change Password</span>
            </button>
          </div>

          <!-- Sub-Tab Content Area -->
          <div class="profile-content-area">
            
            <!-- Sub-Tab 1: Account Information -->
            <div id="paneAccountInfo" class="profile-tab-pane active-pane">
              <div class="clay-card">
                <div class="clay-card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; flex-wrap: wrap; gap: 12px;">
                  <div>
                    <h2 class="clay-card-title">Account Information</h2>
                    <p class="clay-card-subtitle">Master landlord account and property administration details</p>
                  </div>
                  <div>
                    <span class="clay-badge badge-verified" style="font-size: 13px; padding: 7px 16px;">
                      <span>✓</span> Verified Landlord
                    </span>
                  </div>
                </div>

                <div class="detail-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin-bottom: 24px;">
                  <div class="detail-item">
                    <span class="detail-label">Full Name: </span>
                    <span class="detail-value" id="profileFullName" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($user['name'] ?? 'Lead Dimaculangan') ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Username: </span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($user['username'] ?? 'admin') ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Email Address: </span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($user['email'] ?? 'admin@tararent.ph') ?></span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Administrative Role: </span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700; text-transform: capitalize;"><?= htmlspecialchars($user['role'] ?? 'landlord') ?> (Property Manager)</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Managed Property: </span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;">1 Apartment • 3 Units</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Total Active Residents: </span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;"><?= count($allTenants) ?> Residents</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Verification Status: </span>
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;">Verified Master Account</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Sub-Tab 2: Change Password -->
            <div id="paneChangePassword" class="profile-tab-pane">
              <div class="clay-card" style="max-width: 600px;">
                <div class="clay-card-header" style="margin-bottom: 22px;">
                  <h2 class="clay-card-title">Change Password</h2>
                  <p class="clay-card-subtitle">Keep your master landlord account secure with regular updates</p>
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
       MODAL: COMPREHENSIVE PAYMENT DETAILS & MANUAL VERIFICATION AUDIT
       Sections:
         A. Billing Information (Month, Year, Unit, Tenant, Move-in, Due, Paid, Remaining Balance, Status, Due Date)
         B. Payment Information (Method, Submission Date, Ref Number, Receipt Proof Preview/Inspect, Tenant Remarks)
         C. Verification Actions (Remarks/Reason, Approve, Reject, Double-click protection)
       ===================================================================== -->
  <div class="modal-backdrop" id="paymentDetailsModal">
    <div class="clay-modal" style="max-width: 640px; max-height: 92vh; overflow-y: auto;">
      
      <!-- Modal Header -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; border-bottom: 1px solid var(--clay-border); padding-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #a5b4fc, #6366f1); color: #fff; font-size: 22px; display: flex; align-items: center; justify-content: center; box-shadow: 2px 2px 8px rgba(99, 102, 241, 0.3);">
            💳
          </div>
          <div>
            <h2 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; line-height: 1.2;">Payment Verification &amp; Details</h2>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 3px;">
              Review billing metrics, inspect uploaded proof slip, and execute verification decisions
            </p>
          </div>
        </div>
        <button type="button" class="btn-remove-file" onclick="closePaymentDetailsModal()" title="Close details modal">✕</button>
      </div>

      <input type="hidden" id="pdPaymentId" value="">
      <input type="hidden" id="reviewPaymentId" value="">

      <!-- ===================================================================
           SECTION A: BILLING INFORMATION
           =================================================================== -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 16px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 16px;">
        <div style="font-size: 13.5px; font-weight: 800; color: #0f172a; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between;">
          <span style="display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 15px;">📋</span>
            <span>Section A: Billing Information</span>
          </span>
          <span id="pdStatusBadge" class="clay-badge badge-pending">PENDING</span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px;">
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Payment Category:</span>
            <div id="pdPaymentType" style="font-weight: 800; color: #6d28d9; margin-top: 2px; font-size: 14px;">Rent</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Tenant Name:</span>
            <div id="pdTenantName" style="font-weight: 800; color: #0f172a; margin-top: 2px; font-size: 14px;">-</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Apartment Unit:</span>
            <div id="pdUnitNumber" style="font-weight: 800; color: #4338ca; margin-top: 2px; font-size: 14px;">-</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Billing Month:</span>
            <div id="pdBillingMonth" style="font-weight: 800; color: #0f172a; margin-top: 2px;">-</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Billing Year:</span>
            <div id="pdBillingYear" style="font-weight: 800; color: #0f172a; margin-top: 2px;">-</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Date of Moving In:</span>
            <div id="pdMoveInDate" style="font-weight: 800; color: #0f172a; margin-top: 2px;">-</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Payment Due Date:</span>
            <div id="pdDueDate" style="font-weight: 800; color: #dc2626; margin-top: 2px;">-</div>
          </div>
        </div>

        <!-- Financial Figures Row -->
        <div style="margin-top: 14px; padding-top: 14px; border-top: 1px dashed rgba(166, 180, 204, 0.4); display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; text-align: center;">
          <div style="background: #ffffff; padding: 10px 8px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">
            <div style="font-size: 10.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Amount Due</div>
            <div id="pdAmountDue" style="font-size: 15px; font-weight: 800; color: #0f172a; margin-top: 3px;">₱0.00</div>
          </div>
          <div style="background: #ffffff; padding: 10px 8px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">
            <div style="font-size: 10.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Amount Paid</div>
            <div id="pdAmountPaid" style="font-size: 15px; font-weight: 800; color: #047857; margin-top: 3px;">₱0.00</div>
          </div>
          <div style="background: #ffffff; padding: 10px 8px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">
            <div style="font-size: 10.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Remaining Balance</div>
            <div id="pdRemainingBalance" style="font-size: 15px; font-weight: 800; color: #dc2626; margin-top: 3px;">₱0.00</div>
          </div>
        </div>
      </div>

      <!-- ===================================================================
           SECTION B: PAYMENT INFORMATION & PROOF
           =================================================================== -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 16px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 16px;">
        <div style="font-size: 13.5px; font-weight: 800; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
          <span style="font-size: 15px;">🧾</span>
          <span>Section B: Payment Information &amp; Proof</span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px; margin-bottom: 12px;">
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Payment Method:</span>
            <div id="pdPaymentMethod" style="font-weight: 800; color: #0f172a; margin-top: 2px;">GCash / Bank Transfer</div>
          </div>
          <div>
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Payment / Submission Date:</span>
            <div id="pdPaymentDate" style="font-weight: 800; color: #0f172a; margin-top: 2px;">-</div>
          </div>
          <div style="grid-column: 1 / -1;">
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Payment Reference Number:</span>
            <div id="pdRefNumber" style="font-weight: 800; color: #4338ca; margin-top: 2px; font-family: monospace; font-size: 13.5px;">-</div>
          </div>
          <div style="grid-column: 1 / -1;">
            <span style="color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase;">Tenant's Payment Remarks:</span>
            <div id="pdTenantRemarks" style="font-size: 12.5px; color: #334155; margin-top: 3px; font-style: italic; background: #ffffff; padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">None provided</div>
          </div>
        </div>

        <!-- Uploaded Payment Receipt / Proof Slip with Preview & Inspect button -->
        <div style="margin-top: 10px;">
          <label class="form-label" style="font-size: 11.5px; margin-bottom: 6px;">Uploaded Payment Receipt / Proof</label>
          <div id="pdReceiptWrapper" style="padding: 14px; background: var(--clay-card-bg); border-radius: var(--radius-md); border: 1px solid var(--clay-border); text-align: center;">
            <div id="pdNoReceiptMsg" style="font-size: 13px; color: var(--text-muted); padding: 8px 0;">
              No receipt uploaded yet (Awaiting tenant submission).
            </div>
            <div id="pdReceiptContent" style="display: none;">
              <div style="margin-bottom: 10px;">
                <a id="pdReceiptInspectBtn" href="#" target="_blank" class="btn-inspect-proof" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; font-size: 13px;">
                  📄 Inspect Proof in New Tab
                </a>
              </div>
              <!-- Image preview box -->
              <div id="pdReceiptPreviewBox" style="display: none; margin-top: 8px; border-radius: 10px; overflow: hidden; background: rgba(15, 23, 42, 0.04); border: 1px solid var(--clay-border); padding: 8px;">
                <img id="pdReceiptImage" src="" alt="Payment Receipt" style="max-height: 250px; max-width: 100%; object-fit: contain; border-radius: 6px; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.08);" onclick="window.open(this.src, '_blank')" title="Click to open full view in new tab">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===================================================================
           SECTION C: VERIFICATION ACTIONS & AUDIT
           =================================================================== -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 16px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 10px;">
        <div style="font-size: 13.5px; font-weight: 800; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
          <span style="display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 15px;">⚖️</span>
            <span>Section C: Verification Actions</span>
          </span>
          <span id="pdVerifiedMeta" style="font-size: 11px; color: var(--text-muted);"></span>
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label" for="pdVerificationNotes">
            Remarks / Reason for Decision <span id="pdNotesRequiredLabel" style="display: none; color: #ef4444; font-size: 11px;">(Required when rejecting payment)</span>
          </label>
          <textarea id="pdVerificationNotes" class="clay-textarea" placeholder="Enter verification feedback or explanation (e.g. Bank transfer reference confirmed, or Payment proof was blurry/partial amount)..." rows="3"></textarea>
        </div>

        <!-- Verification Action Buttons -->
        <div id="pdActionButtonsRow" style="display: flex; gap: 12px;">
          <button type="button" id="btnPdReject" class="btn-verify-reject" style="flex: 1; padding: 12px; font-size: 13.5px; font-weight: 800;" onclick="handlePaymentVerificationDecision('rejected')">
            ✕ Reject Payment
          </button>
          <button type="button" id="btnPdApprove" class="btn-verify-approve" style="flex: 1; padding: 12px; font-size: 13.5px; font-weight: 800;" onclick="handlePaymentVerificationDecision('approved')">
            ✓ Approve Payment
          </button>
        </div>

        <!-- Special Action for Placeholder Rent Setup -->
        <div id="pdSetupActionRow" style="display: none; margin-top: 10px;">
          <button type="button" class="clay-btn clay-btn-primary" style="width: 100%; padding: 12px; font-size: 13.5px; background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%); color: #fff;" onclick="triggerSetupFromModal()">
            ⚙️ Configure Monthly Rent &amp; Billing Cycle
          </button>
        </div>

        <!-- Processing Indicator with Spinner (Double-Click & Concurrency Protection) -->
        <div id="pdProcessingIndicator" style="display: none; align-items: center; justify-content: center; gap: 10px; color: #4338ca; font-weight: 700; font-size: 13px; padding: 12px 0;">
          <span class="clay-spinner" style="width: 20px; height: 20px; border-width: 2.5px;"></span>
          <span id="pdProcessingText">Processing decision and dispatching notification email...</span>
        </div>
      </div>

    </div>
  </div>

  <!-- =====================================================================
       MODAL: CONFIGURE PLACEHOLDER RENT RECORD
       Allows Landlord to enter: Monthly Rent Amount, Billing Period / Month, Due Date.
       Once configured, activates record to 'pending' awaiting tenant payment.
       ===================================================================== -->
  <div class="modal-backdrop" id="configureRentModal">
    <div class="clay-modal" style="max-width: 520px;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
          <h2 style="font-size: 20px; font-weight: 800; color: #0f172a;" id="configRentTitle">Configure Rent Record</h2>
          <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">Set monthly rent amount, billing cycle, and payment due date</p>
        </div>
        <button type="button" class="btn-remove-file" onclick="closeConfigureRentModal()">✕</button>
      </div>

      <input type="hidden" id="configPaymentId" value="">

      <!-- Target Tenant & Unit Details -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 14px 18px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 20px;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;">
          <div><span style="color: var(--text-muted);">Resident:</span> <strong id="configTenantName" style="color: #0f172a;">-</strong></div>
          <div><span style="color: var(--text-muted);">Apartment Unit:</span> <strong id="configUnitNumber" style="color: #4338ca;">-</strong></div>
        </div>
      </div>

      <form id="configureRentForm" onsubmit="event.preventDefault(); submitConfigureRent();">
        <div class="form-group" style="margin-bottom: 16px;">
          <label class="form-label" for="configMonthlyRent">Monthly Rent Amount (₱) *</label>
          <div style="position: relative;">
            <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-weight: 700; color: var(--text-muted);">₱</span>
            <input type="number" id="configMonthlyRent" class="clay-input" style="padding-left: 32px;" step="0.01" min="1" placeholder="e.g. 8000.00" required>
          </div>
          <span style="font-size: 11px; color: var(--text-muted); margin-top: 4px; display: block;">Standard suggestions: Unit 1: ₱6,000 | Unit 2: ₱8,000 | Unit 3: ₱8,000</span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" for="configBillingCycle">Billing Month *</label>
            <input type="month" id="configBillingCycle" class="clay-input" required>
          </div>
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" for="configDueDate">Payment Due Date *</label>
            <input type="date" id="configDueDate" class="clay-input" required>
          </div>
        </div>

        <div style="background: rgba(124, 58, 237, 0.06); border: 1px solid rgba(124, 58, 237, 0.2); border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 22px; font-size: 12px; color: #4c1d95; line-height: 1.45;">
          ℹ️ <strong>Note:</strong> Configuring this rent converts it to an active pending invoice. It will <strong>NOT</strong> be treated as paid and no receipt is generated until the resident submits payment proof.
        </div>

        <div style="display: flex; gap: 12px; justify-content: flex-end;">
          <button type="button" class="clay-btn clay-btn-secondary" style="padding: 10px 18px;" onclick="closeConfigureRentModal()">
            Cancel
          </button>
          <button type="submit" class="clay-btn clay-btn-primary" style="padding: 10px 22px; background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%); color: #fff;">
            ✓ Save &amp; Activate Rent
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: ADD NEW TENANT TO A UNIT
       Collects: Full Name, Birthday, Email, Phone, and Emergency Contact
       (Name, Relationship, Contact Number).
       Auto-generates sequential username (RC001, RC002, ...) and 6-digit passcode.
       ===================================================================== -->
  <div class="modal-backdrop" id="addTenantModal">
    <div class="clay-modal" style="max-width: 520px;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
        <div>
          <h2 style="font-size: 20px; font-weight: 800; color: #0f172a;">Register New Resident</h2>
          <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">Assign resident to an apartment unit</p>
        </div>
        <button type="button" class="btn-remove-file" onclick="closeAddTenantModal()">✕</button>
      </div>

      <form id="createTenantForm">
        <div class="form-group">
          <label class="form-label" for="newUnitId">Assign to Unit <span class="req">*</span></label>
          <div class="select-wrapper">
            <select id="newUnitId" name="unit_id" class="clay-select" required>
              <?php foreach ($units as $u): ?>
                <?php $isOcc = !empty($unitsWithTenants[$u['id']]['tenants']); ?>
                <option value="<?= $u['id'] ?>" <?= $isOcc ? 'disabled style="color: #94a3b8;"' : '' ?>>
                  <?= htmlspecialchars($u['unit_number']) ?> <?= $isOcc ? '(Occupied - 1 Account Limit)' : '(Available • ₱' . number_format((float)$u['monthly_rent'], 2) . '/mo)' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="newName">Full Name <span class="req">*</span></label>
          <input type="text" id="newName" name="name" class="clay-input" placeholder="e.g. Michael Jordan" required>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
          <div class="form-group">
            <label class="form-label" for="newMoveInDate">Date of Moving In <span class="req">*</span></label>
            <input type="date"
                   id="newMoveInDate"
                   name="move_in_date"
                   class="clay-input"
                   required
                   min="<?= (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') ?>"
                   value="<?= (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="newBirthday">Birthday <span class="req">*</span></label>
            <input type="date"
                   id="newBirthday"
                   name="birthday"
                   class="clay-input"
                   required
                   max="<?= (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') ?>">
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
          <div class="form-group">
            <label class="form-label" for="newEmail">Email Address <span class="req">*</span></label>
            <input type="email" id="newEmail" name="email" class="clay-input" placeholder="michael@example.com" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="newPhone">Phone Number <span class="req">*</span></label>
            <input type="tel" id="newPhone" name="phone" class="clay-input" placeholder="+63 900 000 0000" required>
          </div>
        </div>

        <!-- Requirement: Occupant Breakdown (1 Family per User Account Rule) -->
        <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 14px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 16px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div style="font-size: 13px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px;">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700" style="width: 18px; height: 18px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
              </svg>
              <span>Occupant Breakdown</span>
            </div>
            <span class="clay-badge badge-paid" style="font-size: 10.5px; padding: 2px 8px;">1 Family per Account</span>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" for="newAdultsCount" style="font-size: 11.5px;">Number of Adults <span class="req">*</span></label>
              <input type="number" id="newAdultsCount" name="adults_count" class="clay-input" min="1" value="1" required>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" for="newKidsCount" style="font-size: 11.5px;">Number of Kids <span class="req">*</span></label>
              <input type="number" id="newKidsCount" name="kids_count" class="clay-input" min="0" value="0" required>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" for="newTotalOccupants" style="font-size: 11.5px; display: flex; align-items: center; gap: 4px;">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700" style="width: 14px; height: 14px;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                </svg>
                <span>Total Occupants <span class="req">*</span></span>
              </label>
              <input type="number" id="newTotalOccupants" name="total_occupants" class="clay-input" min="1" value="1" readonly style="background: rgba(226, 232, 240, 0.7); cursor: not-allowed; font-weight: 800; color: #1e293b;">
            </div>
          </div>
        </div>

        <!-- Emergency Contact Details (Separate text boxes for Name, Relationship, and Contact Number) -->
        <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 14px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 16px;">
          <div style="font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
            <span>🚨</span> Emergency Contact Details
          </div>

          <div class="form-group" style="margin-bottom: 10px;">
            <label class="form-label" for="newEmergencyName" style="font-size: 11.5px;">Contact Person's Full Name <span class="req">*</span></label>
            <input type="text" id="newEmergencyName" name="emergency_contact_name" class="clay-input" placeholder="e.g. Deloris Jordan" required>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" for="newEmergencyRel" style="font-size: 11.5px;">Relationship <span class="req">*</span></label>
              <input type="text" id="newEmergencyRel" name="emergency_contact_relationship" class="clay-input" placeholder="e.g. Mother, Spouse, Guardian" required>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" for="newEmergencyPhone" style="font-size: 11.5px;">Contact Number <span class="req">*</span></label>
              <input type="tel" id="newEmergencyPhone" name="emergency_contact_phone" class="clay-input" placeholder="+63 900 000 0000" required>
            </div>
          </div>
        </div>

        <!-- Automated Credentials Notification -->
        <div style="padding: 10px 14px; background: #e0e7ff; border-radius: var(--radius-md); margin-bottom: 18px; font-size: 12px; color: #3730a3; display: flex; align-items: center; gap: 8px;">
          <span>🔒</span>
          <span>Login credentials will be automatically generated in sequential ID format (<strong>RC00X</strong>) with a 6-digit passcode.</span>
        </div>

        <!-- Loading & Status State Container -->
        <div id="tenantCreationStatus" style="display: none; margin-bottom: 16px; padding: 12px 14px; border-radius: var(--radius-md); text-align: center; transition: all 0.25s ease;">
          <div id="tenantCreationLoading" style="display: flex; align-items: center; justify-content: center; gap: 10px; color: #4338ca; font-weight: 700; font-size: 13px;">
            <span class="clay-spinner" style="width: 18px; height: 18px; border-width: 2.5px;"></span>
            <span>Creating tenant account, please wait...</span>
          </div>
          <div id="tenantCreationSuccess" style="display: none; align-items: center; justify-content: center; gap: 8px; color: #047857; font-weight: 700; font-size: 13px;">
            <svg style="width: 18px; height: 18px; color: #10b981; flex-shrink: 0;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            <span id="tenantCreationSuccessText">Tenant account created successfully! Account details have been sent to the tenant's email address.</span>
          </div>
          <div id="tenantCreationError" style="display: none; align-items: center; justify-content: center; gap: 8px; color: #b91c1c; font-weight: 700; font-size: 13px;">
            <svg style="width: 18px; height: 18px; color: #ef4444; flex-shrink: 0;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span id="tenantCreationErrorText"></span>
          </div>
        </div>

        <div style="display: flex; gap: 12px;">
          <button type="button" id="btnCancelCreateTenant" class="clay-btn clay-btn-secondary" style="flex: 1;" onclick="closeAddTenantModal()">Cancel</button>
          <button type="submit" id="btnSubmitCreateTenant" class="clay-btn clay-btn-primary" style="flex: 2;">Register &amp; Generate Credentials</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: AUTO-GENERATED CREDENTIALS DISPLAY (WITH COPY BUTTONS)
       Prominently displays generated username (RC00X) & 6-digit numeric password
       ===================================================================== -->
  <div class="modal-backdrop" id="tenantCredentialsModal">
    <div class="clay-modal" style="max-width: 480px;">
      <div style="text-align: center; margin-bottom: 18px;">
        <div style="font-size: 42px; margin-bottom: 6px;">🎉</div>
        <h2 style="font-size: 22px; font-weight: 800; color: #0f172a;">Tenant Account Provisioned!</h2>
        <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
          Credentials have been auto-generated. Please securely copy and share these with the resident.
        </p>
      </div>

      <div class="credentials-box">
        <div style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 12px; font-weight: 700; text-align: center;">
          Resident: <strong id="credTenantName" style="color: #0f172a;">-</strong> • Unit: <strong id="credUnitNumber" style="color: #4338ca;">-</strong>
        </div>
        
        <div class="credential-row">
          <div>
            <div class="credential-label">Username</div>
            <div class="credential-value" id="credUsername">RC001</div>
          </div>
          <button type="button" class="btn-copy-credential" onclick="copyCredField('credUsername', this)">📋 Copy</button>
        </div>

        <div class="credential-row">
          <div>
            <div class="credential-label">6-Digit Passcode</div>
            <div class="credential-value" id="credPassword">123456</div>
          </div>
          <button type="button" class="btn-copy-credential" onclick="copyCredField('credPassword', this)">📋 Copy</button>
        </div>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 20px;">
        <button type="button" class="clay-btn clay-btn-secondary" style="flex: 1; padding: 12px;" onclick="copyAllCredentials(this)">
          📋 Copy All Details
        </button>
        <button type="button" class="clay-btn clay-btn-primary" style="flex: 1;" onclick="closeCredentialsModalAndReload()">
          Done &amp; View Resident
        </button>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: ANIMATED MAINTENANCE TICKET DETAILS & STATUS MANAGEMENT
       ===================================================================== -->
  <div class="modal-backdrop" id="ticketDetailsModal" onclick="handleTicketModalBackdropClick(event)">
    <div class="ticket-details-modal-box" onclick="event.stopPropagation()">
      
      <!-- Modal Header -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; border-bottom: 1px solid rgba(166, 180, 204, 0.25); padding-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
          <span class="clay-badge" id="mdlTicketNumberBadge" style="background: #e0e7ff; color: #4338ca; font-weight: 800; font-size: 13.5px; padding: 4px 11px;">#MT-0000</span>
          <div>
            <h2 id="mdlTicketTitle" style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; line-height: 1.3;">Maintenance Request</h2>
            <p style="font-size: 12px; color: var(--text-muted); margin: 3px 0 0 0;">
              Submitted on <span id="mdlDateSubmitted" style="font-weight: 600; color: #334155;">-</span>
            </p>
          </div>
        </div>
        <button type="button" class="btn-remove-file" onclick="closeTicketDetailsModal()" title="Close details (Esc)" style="cursor: pointer; font-size: 18px; padding: 4px 8px; border-radius: 8px; border: none; background: transparent; color: var(--text-muted);">✕</button>
      </div>

      <!-- Badges row: Category, Priority, Current Status -->
      <div style="display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; align-items: center;">
        <span class="clay-badge" id="mdlCategoryBadge" style="font-size: 11.5px; font-weight: 700; padding: 4px 10px;">Category: General</span>
        <span class="clay-badge" id="mdlPriorityBadge" style="font-size: 11.5px; font-weight: 700; padding: 4px 10px;">Priority: Medium</span>
        <span class="clay-badge" id="mdlStatusBadge" style="font-size: 11.5px; font-weight: 700; padding: 4px 10px;">Status: Pending</span>
      </div>

      <!-- Scrollable content area -->
      <div style="display: flex; flex-direction: column; gap: 16px;">
        
        <!-- 1. Tenant & Unit Info Grid -->
        <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 14px 16px; box-shadow: var(--clay-sunken-shadow);">
          <div style="font-size: 12.5px; font-weight: 800; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
            <span>👤</span> Tenant &amp; Unit Information
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;">
            <div><strong style="color: var(--text-muted);">Tenant Name:</strong> <span id="mdlTenantName" style="font-weight: 700; color: #0f172a;">-</span></div>
            <div><strong style="color: var(--text-muted);">Assigned Unit:</strong> <span class="clay-badge" id="mdlUnitBadge" style="background: #e0e7ff; color: #4338ca; font-weight: 800; font-size: 11.5px; padding: 2px 8px;">-</span></div>
            <div style="grid-column: 1 / -1;"><strong style="color: var(--text-muted);">Email Address:</strong> <span id="mdlTenantEmail" style="font-weight: 600; color: #334155;">-</span></div>
          </div>
        </div>

        <!-- 2. Complete Issue Description -->
        <div>
          <div style="font-size: 12.5px; font-weight: 800; color: #0f172a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
            <span>📝</span> Complete Issue Description
          </div>
          <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 14px 16px; box-shadow: var(--clay-sunken-shadow); min-height: 80px;">
            <p id="mdlDescription" style="font-size: 13.5px; color: #1e293b; line-height: 1.6; white-space: pre-wrap; margin: 0;"></p>
          </div>
        </div>

        <!-- 3. Attached Images or Supporting Photos -->
        <div>
          <div style="font-size: 12.5px; font-weight: 800; color: #0f172a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
            <span>📷</span> Attached Images &amp; Supporting Photos
          </div>
          <div id="mdlPhotosContainer" style="display: flex; gap: 12px; flex-wrap: wrap;"></div>
          <div id="mdlNoPhotosNotice" style="display: none; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: var(--radius-md, 12px); padding: 18px; text-align: center; color: var(--text-muted); font-size: 12.5px;">
            No photos attached to this maintenance request.
          </div>
        </div>

        <!-- 4. Dedicated Status Update Section Inside the Modal -->
        <div style="background: #ffffff; border: 1.5px solid rgba(99, 102, 241, 0.35); border-radius: var(--radius-md, 12px); padding: 16px; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.08);">
          <div style="font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
            <span>⚙️</span> Update Maintenance Status
          </div>
          <form id="formModalStatusUpdate" onsubmit="event.preventDefault(); submitModalStatusUpdate();">
            <input type="hidden" id="mdlTicketId" value="">

            <div class="form-group" style="margin-bottom: 12px;">
              <label class="form-label" for="mdlSelectStatus" style="font-weight: 700; color: #0f172a; font-size: 12.5px;">Status *</label>
              <select id="mdlSelectStatus" class="clay-select" style="width: 100%; font-weight: 700; font-size: 13.5px; padding: 9px 12px;">
                <option value="Pending">⏳ Pending</option>
                <option value="On-Going">🔧 On-Going</option>
                <option value="Done">✅ Done</option>
              </select>
              <p style="font-size: 11.5px; color: var(--text-muted); margin: 4px 0 0 2px;">
                Setting status to <strong>Done</strong> automatically moves this ticket to Completed Maintenance.
              </p>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
              <label class="form-label" for="mdlResolutionNotes" style="font-weight: 700; color: #0f172a; font-size: 12.5px;">Owner Remarks / Instructions (Optional)</label>
              <textarea id="mdlResolutionNotes" class="clay-textarea" rows="3" placeholder="Add instructions, contractor notes, or an update for the tenant..." style="font-size: 13px;"></textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
              <button type="button" class="clay-btn clay-btn-secondary" onclick="closeTicketDetailsModal()" style="padding: 9px 16px; font-size: 13px;">Cancel</button>
              <button type="submit" id="btnModalSubmitStatus" class="clay-btn clay-btn-primary" style="padding: 9px 20px; font-size: 13px; font-weight: 800;">
                <span>💾 Save Changes</span>
              </button>
            </div>
          </form>
        </div>

      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: EXPANDED TENANT DETAILS & PRIVACY (LANDLORD UI)
       Displays: Complete Info (Emergency contacts, 1 family occupant breakdown).
       ID Display: Tenant's uploaded Valid ID image is immediately visible.
       STRICT PRIVACY: Live Face Scan photo is strictly excluded from UI.
       ===================================================================== -->
  <div class="modal-backdrop" id="tenantDetailsModal">
    <div class="clay-modal" style="max-width: 580px; max-height: 90vh; overflow-y: auto;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; border-bottom: 1px solid var(--clay-border); padding-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div id="tdAvatar" style="width: 46px; height: 46px; border-radius: 14px; background: linear-gradient(135deg, #a5b4fc, #818cf8); color: #fff; font-weight: 800; font-size: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            T
          </div>
          <div>
            <h2 id="tdName" style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; line-height: 1.2;">Tenant Name</h2>
            <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap;">
              <span id="tdUsername" style="font-size: 12px; font-weight: 700; color: var(--clay-primary-dark); background: #e0e7ff; padding: 2px 7px; border-radius: 5px;">@RC001</span>
              <span id="tdUnitBadge" class="clay-badge badge-pending" style="font-size: 11px;">Unit 101</span>
              <span id="tdVerificationBadge" class="clay-badge badge-verified" style="font-size: 11px;">Verified</span>
            </div>
          </div>
        </div>
        <button type="button" class="btn-remove-file" onclick="closeTenantDetailsModal()">✕</button>
      </div>

      <!-- 1. Occupant Breakdown (1 Family per Account Rule) -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 14px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
          <div style="font-size: 13px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px;">
            <span>👨‍👩‍👧‍👦</span> Occupant Breakdown
          </div>
          <span class="clay-badge badge-paid" style="font-size: 10.5px; padding: 2px 8px;">1 Family per Account</span>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; text-align: center;">
          <div style="background: #ffffff; padding: 8px 10px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Total Occupants</div>
            <div style="display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 2px;">
              <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-700">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
              </svg>
              <span id="tdTotalOccupants" style="font-size: 18px; font-weight: 800; color: #0f172a;">1</span>
            </div>
          </div>
          <div style="background: #ffffff; padding: 8px 10px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Adults</div>
            <div id="tdAdultsCount" style="font-size: 18px; font-weight: 800; color: var(--clay-primary-dark); margin-top: 2px;">1</div>
          </div>
          <div style="background: #ffffff; padding: 8px 10px; border-radius: var(--radius-sm); border: 1px solid var(--clay-border);">
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Kids</div>
            <div id="tdKidsCount" style="font-size: 18px; font-weight: 800; color: #059669; margin-top: 2px;">0</div>
          </div>
        </div>
      </div>

      <!-- 2. Personal & Contact Details -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 14px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 16px;">
        <div style="font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
          <div style="display: flex; align-items: center; gap: 6px;">
            <span>📋</span> Personal &amp; Contact Information
          </div>
          <span id="tdRentalStatusBadge" class="clay-badge badge-paid" style="font-size: 11px; padding: 2px 8px;">Active Resident</span>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;">
          <div><strong style="color: var(--text-muted);">Birthday:</strong> <span id="tdBirthday" style="font-weight: 700; color: #0f172a;">N/A</span></div>
          <div><strong style="color: var(--text-muted);">Moved In:</strong> <span id="tdMoveIn" style="font-weight: 700; color: #0f172a;">N/A</span></div>
          <div style="grid-column: 1 / -1;"><strong style="color: var(--text-muted);">Email Address:</strong> <span id="tdEmail" style="font-weight: 700; color: #0f172a;">email@example.com</span></div>
          <div style="grid-column: 1 / -1;"><strong style="color: var(--text-muted);">Phone Number:</strong> <span id="tdPhone" style="font-weight: 700; color: #0f172a;">+63 900 000 0000</span></div>
        </div>
        <div id="tdMovedOutNotice" style="display: none; margin-top: 10px; padding: 10px 12px; background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; font-size: 12px; color: #92400e;">
          <strong>⚠️ Move-Out Status:</strong> This tenant is officially marked as Moved Out. Unpaid bills or pending payment proofs remain. The account and payment records will stay active until all balances are fully settled.
        </div>
      </div>

      <!-- 3. Emergency Contact Details -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 14px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 16px;">
        <div style="font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
          <span>🚨</span> Emergency Contact
        </div>
        <div style="font-size: 13px;">
          <div><strong style="color: var(--text-muted);">Contact Name:</strong> <span id="tdEmergencyName" style="font-weight: 700; color: #0f172a;">None listed</span></div>
          <div style="margin-top: 4px;"><strong style="color: var(--text-muted);">Relationship:</strong> <span id="tdEmergencyRel" style="font-weight: 700; color: #0f172a;">N/A</span></div>
          <div style="margin-top: 4px;"><strong style="color: var(--text-muted);">Contact Number:</strong> <span id="tdEmergencyPhone" style="font-weight: 700; color: #0f172a;">N/A</span></div>
        </div>
      </div>

      <!-- 4. Uploaded Valid ID (Directly Visible, No Extra Click Needed; Strict Face Privacy: Live Scan Excluded) -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 14px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
          <div style="font-size: 13px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px;">
            <span>📄</span> Uploaded Valid ID Image
          </div>
          <span id="tdIdType" style="font-size: 11.5px; font-weight: 700; color: var(--clay-primary-dark);">Government ID</span>
        </div>
        <div id="tdIdContainer" style="text-align: center; margin-top: 8px;">
          <!-- Directly displays ID image or clean fallback -->
        </div>
      </div>

      <!-- Actions -->
      <div style="display: flex; gap: 10px; margin-top: 14px;">
        <button type="button" class="clay-btn clay-btn-secondary" style="flex: 1;" onclick="closeTenantDetailsModal()">Close</button>
        <button type="button" id="tdBtnDelete" class="btn-moveout-delete" style="flex: 1.5; padding: 12px; margin-top: 0;">
          🗑️ Delete Account (Moved Out)
        </button>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       MODAL: ANIMATED ARCHIVED TENANT DETAILS & RESTORATION
       ===================================================================== -->
  <div class="modal-backdrop" id="archivedTenantDetailsModal" onclick="handleArchivedModalBackdropClick(event)">
    <div class="archived-modal-box" onclick="event.stopPropagation()">
      <input type="hidden" id="archivedModalTenantId" value="">

      <!-- Header: Avatar, Name, Username, Archived Badge, Close Button -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 1px solid rgba(166, 180, 204, 0.25); padding-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 14px;">
          <div id="archivedModalAvatar" style="width: 50px; height: 50px; border-radius: 14px; background: linear-gradient(135deg, #64748b, #475569); color: #fff; font-weight: 800; font-size: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
            T
          </div>
          <div>
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
              <h2 id="archivedModalName" style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; line-height: 1.2;">Archived Tenant Name</h2>
              <span id="archivedModalUsernameBadge" style="font-size: 12px; font-weight: 700; color: var(--clay-primary-dark); background: #e0e7ff; padding: 2px 8px; border-radius: 6px;">@RC001</span>
              <span class="clay-badge" style="background: #fee2e2; color: #991b1b; font-size: 11px; font-weight: 800; padding: 3px 10px;">ARCHIVED</span>
            </div>
            <p id="archivedModalDeletedDate" style="font-size: 12.5px; color: var(--text-muted); margin: 4px 0 0 0;">Account deleted and archived on -</p>
          </div>
        </div>
        <button type="button" class="btn-remove-file" onclick="closeArchivedTenantModal()" title="Close (Esc)" style="cursor: pointer; font-size: 18px; padding: 4px 8px; border-radius: 8px; border: none; background: transparent; color: var(--text-muted);">✕</button>
      </div>

      <!-- Scrollable content sections -->
      <div style="display: flex; flex-direction: column; gap: 18px;">

        <!-- 1. PERSONAL INFORMATION & RENTAL INFORMATION (2 Columns) -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          
          <!-- Card A: Personal Information -->
          <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 16px; box-shadow: var(--clay-sunken-shadow);">
            <div style="font-size: 12.5px; font-weight: 800; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
              <span>👤</span> Personal Information
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 13px;">
              <div><span style="color: var(--text-muted);">Full Name:</span> <strong id="archivedPersonalName" style="color: #0f172a;">-</strong></div>
              <div><span style="color: var(--text-muted);">Email Address:</span> <span id="archivedPersonalEmail" style="color: #334155; font-weight: 600;">-</span></div>
              <div><span style="color: var(--text-muted);">Contact Number:</span> <span id="archivedPersonalPhone" style="color: #334155; font-weight: 600;">-</span></div>
              <div><span style="color: var(--text-muted);">Complete Address:</span> <span id="archivedPersonalAddress" style="color: #334155; font-weight: 600;">-</span></div>
              <div><span style="color: var(--text-muted);">Date of Birth:</span> <strong id="archivedPersonalBirthday" style="color: #0f172a;">-</strong></div>
            </div>
          </div>

          <!-- Card B: Rental Information -->
          <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 16px; box-shadow: var(--clay-sunken-shadow);">
            <div style="font-size: 12.5px; font-weight: 800; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
              <span>🏠</span> Rental Information
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 13px;">
              <div><span style="color: var(--text-muted);">Assigned Unit:</span> <span class="clay-badge" id="archivedRentalUnit" style="background: #e0e7ff; color: #4338ca; font-weight: 800; font-size: 11.5px; padding: 2px 8px;">-</span></div>
              <div><span style="color: var(--text-muted);">Date of Moving In:</span> <strong id="archivedRentalMoveIn" style="color: #0f172a;">-</strong></div>
              <div><span style="color: var(--text-muted);">Monthly Rent:</span> <strong id="archivedRentalMonthlyRent" style="color: #0f172a;">₱3,000.00 / mo</strong></div>
              <div><span style="color: var(--text-muted);">Advance Deposit:</span> <strong id="archivedRentalDeposit" style="color: #047857;">₱3,000.00</strong></div>
              <div><span style="color: var(--text-muted);">Account Created:</span> <span id="archivedRentalCreatedAt" style="color: #334155; font-weight: 600;">-</span></div>
              <div><span style="color: var(--text-muted);">Date Archived:</span> <span id="archivedRentalDeletedAt" style="color: #be123c; font-weight: 700;">-</span></div>
            </div>
          </div>

        </div>

        <!-- 2. ACCOUNT INFORMATION -->
        <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); padding: 14px 16px; box-shadow: var(--clay-sunken-shadow); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
          <div>
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Username</div>
            <strong id="archivedAccountUsername" style="font-size: 14px; color: #0f172a;">@RC001</strong>
          </div>
          <div>
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Account Status</div>
            <span class="clay-badge" style="background: #fee2e2; color: #991b1b; font-weight: 800; font-size: 11.5px; padding: 3px 10px;">Archived (Moved Out)</span>
          </div>
          <div>
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">ID Verification</div>
            <span id="archivedAccountIdStatus" style="font-size: 12.5px; font-weight: 600; color: #059669;">Verified Government ID</span>
          </div>
        </div>

        <!-- 3. PAYMENT RECORDS (Historical Payments) -->
        <div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div style="font-size: 13px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px;">
              <span>💳</span> Previous Payment History &amp; Billing Records
            </div>
            <span id="archivedPaymentCountBadge" class="clay-badge" style="font-size: 11px; background: #f1f5f9; color: #475569; font-weight: 700;">
              0 Records
            </span>
          </div>

          <div style="background: var(--clay-input-bg, #f8fafc); border-radius: var(--radius-md, 12px); overflow: hidden; box-shadow: var(--clay-sunken-shadow); border: 1px solid rgba(166, 180, 204, 0.2);">
            <div style="overflow-x: auto; max-height: 240px; overflow-y: auto;">
              <table style="width: 100%; border-collapse: collapse; font-size: 12.5px; text-align: left;">
                <thead style="position: sticky; top: 0; z-index: 10;">
                  <tr style="border-bottom: 1.5px solid rgba(166, 180, 204, 0.3); background: #f1f5f9; color: var(--text-muted); font-weight: 700;">
                    <th style="padding: 10px 14px;">Billing Month</th>
                    <th style="padding: 10px 14px;">Amount Due</th>
                    <th style="padding: 10px 14px;">Amount Paid</th>
                    <th style="padding: 10px 14px;">Payment Date</th>
                    <th style="padding: 10px 14px;">Payment Method</th>
                    <th style="padding: 10px 14px;">Status</th>
                    <th style="padding: 10px 14px; text-align: center;">Receipt / Proof</th>
                  </tr>
                </thead>
                <tbody id="archivedPaymentsList">
                  <tr>
                    <td colspan="7" style="text-align: center; padding: 24px 14px; color: var(--text-muted);">
                      Loading payment records...
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- 4. MODAL FOOTER & ARCHIVED TENANT FUNCTIONS -->
        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(166, 180, 204, 0.25); padding-top: 16px; margin-top: 6px; flex-wrap: wrap; gap: 12px;">
          <div>
            <button type="button" id="btnPermanentDeleteArchived" class="btn-moveout-delete" style="padding: 9px 16px; font-size: 12.5px; margin: 0;" onclick="submitPermanentDeleteArchived()">
              🗑️ Permanently Delete Account
            </button>
          </div>
          <div style="display: flex; gap: 10px; align-items: center;">
            <button type="button" class="clay-btn clay-btn-secondary" onclick="closeArchivedTenantModal()" style="padding: 10px 18px; font-size: 13px;">
              Close
            </button>
            <button type="button" id="btnRestoreArchivedAccount" class="clay-btn clay-btn-primary" style="padding: 10px 22px; font-size: 13px; font-weight: 800; background: linear-gradient(135deg, #059669, #047857); border-color: #047857;" onclick="submitRestoreArchivedAccount()">
              <span>♻️ Restore Account</span>
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>
  <div class="modal-backdrop" id="announcementModal">
    <div class="clay-modal" style="max-width: 560px;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
          <h2 style="font-size: 20px; font-weight: 800; color: #0f172a;">📢 Send Rent Due Announcement</h2>
          <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">Broadcast rental reminders and instructions to tenants via email</p>
        </div>
        <button type="button" class="btn-remove-file" onclick="closeAnnouncementModal()">✕</button>
      </div>

      <form id="announcementForm" onsubmit="event.preventDefault(); submitAnnouncement();">
        <div class="form-group" style="margin-bottom: 16px;">
          <label class="form-label" for="announcementTitle">Announcement Title *</label>
          <input type="text" id="announcementTitle" class="clay-input" placeholder="e.g. Monthly Rent Collection Notice - November" required>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" for="announcementTargetType">Recipients *</label>
            <select id="announcementTargetType" class="clay-select" style="width: 100%;" onchange="toggleAnnouncementUnitSelector(this.value)">
              <option value="all">All Active Tenants</option>
              <option value="selected">Specific Units Only</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" for="announcementDueDate">Due Date</label>
            <input type="date" id="announcementDueDate" class="clay-input">
          </div>
        </div>

        <div class="form-group" id="announcementUnitsContainer" style="display: none; margin-bottom: 16px;">
          <label class="form-label">Select Units</label>
          <div style="display: flex; gap: 14px; background: var(--clay-input-bg); padding: 10px 14px; border-radius: var(--radius-md);">
            <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
              <input type="checkbox" name="announcement_units[]" value="1"> Unit 101
            </label>
            <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
              <input type="checkbox" name="announcement_units[]" value="2"> Unit 102
            </label>
            <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
              <input type="checkbox" name="announcement_units[]" value="3"> Unit 103
            </label>
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 20px;">
          <label class="form-label" for="announcementMessage">Custom Message from Owner *</label>
          <textarea id="announcementMessage" class="clay-textarea" rows="4" placeholder="Enter custom message to residents regarding payment schedules, accepted payment methods (GCash / Bank Transfer), and guidelines..." required></textarea>
        </div>

        <div style="display: flex; gap: 12px;">
          <button type="button" class="clay-btn btn-secondary" style="flex: 1; padding: 12px;" onclick="closeAnnouncementModal()">
            Cancel
          </button>
          <button type="submit" id="btnSubmitAnnouncement" class="clay-btn clay-btn-primary" style="flex: 1; padding: 12px;">
            <span>Send Announcement Emails</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Clay Toast Notification -->
  <div class="clay-toast" id="clayToast">
    <span class="toast-icon" id="toastIcon">✨</span>
    <span class="toast-message" id="toastMessage">Done</span>
  </div>

  <script src="assets/js/charts.js"></script>
  <script>
    function showToast(msg, type = 'success') {
      const t = document.getElementById('clayToast');
      document.getElementById('toastMessage').textContent = msg;
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3500);
    }

    // Single Unified Retractable Sidebar Toggle System (Strictly 1 Toggle in DOM)
    function toggleSidebar() {
      const sidebar = document.getElementById('appSidebar');
      const overlay = document.querySelector('.sidebar-overlay');
      if (window.innerWidth <= 960) {
        sidebar.classList.toggle('mobile-open');
        if (overlay) overlay.classList.toggle('active');
      } else {
        sidebar.classList.toggle('retracted');
      }
    }

    function closeSidebarMobile() {
      const sidebar = document.getElementById('appSidebar');
      const overlay = document.querySelector('.sidebar-overlay');
      if (sidebar) sidebar.classList.remove('mobile-open');
      if (overlay) overlay.classList.remove('active');
    }

    // Sidebar Section Switching:
    // 1. Fully Clickable: Navigates smoothly to the target section and activates the clicked sidebar tab.
    // 2. Persistent Sidebar State: When these sidebar tabs or buttons are clicked, the sidebar must NOT auto-collapse.
    //    The sidebar strictly remains open and only collapses or hides when the main toggle button (☰) is explicitly clicked.
    function switchSection(sectionId, btn, updateUrl = true) {
      if (!sectionId) return;

      if (sectionId === 'sectionArchivedTenants') {
        const dirBtn = document.getElementById('navTenantDirectory');
        switchSection('sectionDirectory', dirBtn, updateUrl);
        switchDirectoryTab('archived');
        return;
      }

      if (sectionId === 'sectionProfile') {
        openProfileView('accountInfo', updateUrl);
        return;
      }

      document.querySelectorAll('.app-view-section').forEach(s => s.classList.remove('active-section'));
      document.querySelectorAll('.sidebar-nav-item').forEach(b => b.classList.remove('active'));

      const target = document.getElementById(sectionId);
      if (target) {
        target.classList.add('active-section');
      }

      let activeBtn = btn;
      if (!activeBtn) {
        activeBtn = document.querySelector(`.sidebar-nav-item[data-section="${sectionId}"]`) ||
                    document.querySelector(`.sidebar-nav-item[onclick*="${sectionId}"]`);
      }
      if (activeBtn) {
        activeBtn.classList.add('active');
      }

      if (sectionId === 'sectionAnalytics') {
        loadDashboardAnalytics();
      }

      // Hide Settings button when leaving profile section
      updateProfileSettingsVisibility(false);

      // Universal Contextual Routing: update URL to reflect current sidebar category
      if (updateUrl && window.history && window.history.replaceState) {
        history.replaceState(null, '', `admin_dashboard.php?section=${sectionId}`);
      }

      closeSettingsMenu();

      // Persistent Sidebar State:
      // The sidebar must NOT auto-collapse on navigation.
      // It remains open and strictly only collapses when the main toggle button (☰) is explicitly clicked.
    }

    // Synchronize user profile information and settings state
    function updateProfileSettingsVisibility(isProfileOpen) {
      const nameEl = document.getElementById('headerUserName');
      if (nameEl) {
        nameEl.textContent = <?= json_encode($user['name'] ?? 'Lead Dimaculangan') ?>;
      }
      const profNameEl = document.getElementById('profileFullName');
      if (profNameEl) {
        profNameEl.textContent = <?= json_encode($user['name'] ?? 'Lead Dimaculangan') ?>;
      }
      const dropNameEl = document.getElementById('profileDropdownName');
      if (dropNameEl) {
        dropNameEl.textContent = <?= json_encode($user['name'] ?? 'Lead Dimaculangan') ?>;
      }
    }

    // Settings Dropdown Popover Control (Upper-Right Corner)
    function toggleSettingsMenu(e) {
      if (e) {
        e.preventDefault();
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

    // Profile View & Internal Sub-Sidebar Navigation (Sidebar strictly remains open and persistent)
    function openProfileView(tab = 'accountInfo', updateUrl = true) {
      document.querySelectorAll('.app-view-section').forEach(s => s.classList.remove('active-section'));
      document.querySelectorAll('.sidebar-nav-item').forEach(b => b.classList.remove('active'));

      const profSec = document.getElementById('sectionProfile');
      if (profSec) profSec.classList.add('active-section');

      // Show Settings button along with user profile information
      updateProfileSettingsVisibility(true);

      switchProfileSubTab(tab, null, updateUrl);
      closeSettingsMenu();

      // Sidebar strictly remains open and visible
    }

    function switchProfileSubTab(tabName, clickedBtn = null, updateUrl = true) {
      document.querySelectorAll('.profile-tab-pane').forEach(p => p.classList.remove('active-pane'));
      document.querySelectorAll('.profile-subnav-item').forEach(b => b.classList.remove('active'));

      if (tabName === 'accountInfo') {
        const pane = document.getElementById('paneAccountInfo');
        if (pane) pane.classList.add('active-pane');
        const btn = clickedBtn || document.getElementById('adminSubnavAccount');
        if (btn) btn.classList.add('active');
      } else if (tabName === 'changePassword') {
        const pane = document.getElementById('paneChangePassword');
        if (pane) pane.classList.add('active-pane');
        const btn = clickedBtn || document.getElementById('adminSubnavPassword');
        if (btn) btn.classList.add('active');
      }

      if (updateUrl && window.history && window.history.replaceState) {
        history.replaceState(null, '', `admin_dashboard.php?section=sectionProfile&tab=${tabName}`);
      }
    }

    // Landlord Approves or Rejects Tenant ID Document
    async function reviewTenantId(tenantId, decision) {
      const confirmMsg = decision === 'verified' 
        ? 'Approve and mark this tenant ID as verified?' 
        : 'Reject this tenant ID submission?';
      if (!confirm(confirmMsg)) return;

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'verify_tenant_id',
            tenant_id: tenantId,
            decision: decision
          })
        });
        const result = await res.json();
        if (res.ok && result.status === 'success') {
          showToast(result.message);
          setTimeout(() => {
            window.location.href = window.location.href;
          }, 1000);
        } else {
          showToast(result.message || 'Error updating ID verification', 'error');
        }
      } catch (err) {
        showToast('Network error reviewing ID', 'error');
      }
    }

    document.getElementById('changePasswordForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const currentPassword = document.getElementById('profCurrentPass').value;
      const newPassword = document.getElementById('profNewPass').value;
      const confirmPassword = document.getElementById('profConfirmPass').value;

      if (newPassword !== confirmPassword) {
        showToast('New passwords do not match.', 'error');
        return;
      }

      if (newPassword.length < 6) {
        showToast('Password must be at least 6 characters.', 'error');
        return;
      }

      const btn = document.getElementById('btnSavePassword');
      btn.disabled = true;
      btn.textContent = 'Updating...';

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
          document.getElementById('changePasswordForm').reset();
          // Universal Contextual Routing: Stay on Profile -> Change Password tab
          setTimeout(() => {
            window.location.href = 'admin_dashboard.php?section=sectionProfile&tab=changePassword';
          }, 1000);
        } else {
          showToast(data.message || 'Error updating password', 'error');
        }
      } catch (err) {
        showToast('Network error updating password', 'error');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Update Password';
      }
    });

    // Folder View Controls (Tenant Directory 3 Folders Interface)
    function openUnitFolder(unitId) {
      document.getElementById('directoryFoldersView').style.display = 'none';
      document.getElementById('directoryFolderDetailView').style.display = 'block';
      document.querySelectorAll('.unit-folder-content').forEach(el => el.style.display = 'none');
      const target = document.getElementById('unit-detail-folder-' + unitId);
      if (target) {
        target.style.display = 'block';
      }
      history.replaceState(null, '', `admin_dashboard.php?section=sectionDirectory&unit_id=${unitId}`);
    }

    function closeUnitFolder() {
      document.getElementById('directoryFolderDetailView').style.display = 'none';
      document.getElementById('directoryFoldersView').style.display = 'block';
      document.querySelectorAll('.unit-folder-content').forEach(el => el.style.display = 'none');
      history.replaceState(null, '', `admin_dashboard.php?section=sectionDirectory`);
    }

    // List of occupied unit IDs for strict 1 account per unit limit
    let occupiedUnitIds = <?= json_encode(array_values(array_map(fn($u) => (int)$u['id'], array_filter($unitsWithTenants, fn($u) => !empty($u['tenants']))))) ?>;
    let isSubmittingTenant = false;

    function resetAddTenantModalState() {
      isSubmittingTenant = false;
      const submitBtn = document.getElementById('btnSubmitCreateTenant');
      const cancelBtn = document.getElementById('btnCancelCreateTenant');
      const statusContainer = document.getElementById('tenantCreationStatus');
      const loadingEl = document.getElementById('tenantCreationLoading');
      const successEl = document.getElementById('tenantCreationSuccess');
      const errorEl = document.getElementById('tenantCreationError');

      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('btn-loading-state');
        submitBtn.innerHTML = 'Register &amp; Generate Credentials';
      }
      if (cancelBtn) {
        cancelBtn.disabled = false;
      }
      if (statusContainer) statusContainer.style.display = 'none';
      if (loadingEl) loadingEl.style.display = 'flex';
      if (successEl) successEl.style.display = 'none';
      if (errorEl) errorEl.style.display = 'none';

      const moveInInput = document.getElementById('newMoveInDate');
      if (moveInInput) {
        const todayStr = getManilaTodayStr();
        moveInInput.min = todayStr;
        moveInInput.value = todayStr;
      }
    }

    // =========================================================================
    // CUSTOM CLAYMORPHIC CALENDAR (ASIA/MANILA TIMEZONE & RESTRICTIONS)
    // =========================================================================
    function getManilaDateDetails() {
      const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric'
      });
      const parts = formatter.formatToParts(new Date());
      let year = 2026, month = 10, day = 31;
      for (const p of parts) {
        if (p.type === 'year') year = parseInt(p.value, 10);
        if (p.type === 'month') month = parseInt(p.value, 10);
        if (p.type === 'day') day = parseInt(p.value, 10);
      }
      const yyyy = year;
      const mm = String(month).padStart(2, '0');
      const dd = String(day).padStart(2, '0');
      return {
        year,
        month, // 1..12
        day,
        isoString: `${yyyy}-${mm}-${dd}`
      };
    }

    function getManilaTodayStr() {
      return getManilaDateDetails().isoString;
    }

    function initMoveInDatePicker() {
      const moveInInput = document.getElementById('newMoveInDate');
      if (moveInInput) {
        const todayStr = getManilaTodayStr();
        moveInInput.min = todayStr;
        if (!moveInInput.value || moveInInput.value < todayStr) {
          moveInInput.value = todayStr;
        }
      }
    }

    // Modal Control
    function openAddTenantModal(preselectedUnitId = null) {
      if (preselectedUnitId) {
        const uId = parseInt(preselectedUnitId, 10);
        if (occupiedUnitIds.includes(uId)) {
          showToast("This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.", "error");
          return;
        }
        const sel = document.getElementById('newUnitId');
        if (sel) sel.value = preselectedUnitId;
      }
      resetAddTenantModalState();
      initMoveInDatePicker();
      calculateTotalOccupants();
      document.getElementById('addTenantModal').classList.add('open');
    }

    function closeAddTenantModal() {
      if (isSubmittingTenant) return; // Prevent closing during active processing
      document.getElementById('addTenantModal').classList.remove('open');
      resetAddTenantModalState();
    }

    // Auto-Calculate Occupants (Vanilla JS)
    function calculateTotalOccupants() {
      const adultsInput = document.getElementById('newAdultsCount');
      const kidsInput = document.getElementById('newKidsCount');
      const totalInput = document.getElementById('newTotalOccupants');
      if (!totalInput) return;

      const adultsVal = adultsInput ? parseInt(adultsInput.value, 10) : 0;
      const kidsVal = kidsInput ? parseInt(kidsInput.value, 10) : 0;
      const adults = isNaN(adultsVal) || adultsVal < 0 ? 0 : adultsVal;
      const kids = isNaN(kidsVal) || kidsVal < 0 ? 0 : kidsVal;
      const total = adults + kids;
      totalInput.value = total > 0 ? total : 1;
    }

    // Attach Vanilla JS event listeners for real-time recalculation
    const adultsEl = document.getElementById('newAdultsCount');
    const kidsEl = document.getElementById('newKidsCount');
    if (adultsEl) {
      adultsEl.addEventListener('input', calculateTotalOccupants);
      adultsEl.addEventListener('change', calculateTotalOccupants);
      adultsEl.addEventListener('keyup', calculateTotalOccupants);
    }
    if (kidsEl) {
      kidsEl.addEventListener('input', calculateTotalOccupants);
      kidsEl.addEventListener('change', calculateTotalOccupants);
      kidsEl.addEventListener('keyup', calculateTotalOccupants);
    }

    // Handle Tenant Creation with Auto-Generated Credentials & Animated Loading State
    let currentGeneratedCreds = null;
    document.getElementById('createTenantForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      if (isSubmittingTenant) return;

      const unitId = parseInt(document.getElementById('newUnitId').value, 10);
      if (occupiedUnitIds.includes(unitId)) {
        showToast("This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.", "error");
        return;
      }

      const moveInVal = document.getElementById('newMoveInDate') ? document.getElementById('newMoveInDate').value.trim() : '';
      if (!moveInVal) {
        showToast("Date of Moving In is required.", "error");
        return;
      }

      const manilaToday = getManilaTodayStr();
      if (moveInVal < manilaToday) {
        showToast("Invalid moving-in date. Please select today or a future date.", "error");
        resetAddTenantModalState();
        return;
      }

      // 1. Immediately disable buttons and display loading animation
      isSubmittingTenant = true;
      const submitBtn = document.getElementById('btnSubmitCreateTenant');
      const cancelBtn = document.getElementById('btnCancelCreateTenant');
      const statusContainer = document.getElementById('tenantCreationStatus');
      const loadingEl = document.getElementById('tenantCreationLoading');
      const successEl = document.getElementById('tenantCreationSuccess');
      const errorEl = document.getElementById('tenantCreationError');

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.classList.add('btn-loading-state');
        submitBtn.innerHTML = '<span class="clay-spinner" style="width: 15px; height: 15px; border-width: 2px; margin-right: 8px; vertical-align: middle;"></span>Creating Account...';
      }
      if (cancelBtn) {
        cancelBtn.disabled = true;
      }

      if (statusContainer) {
        statusContainer.style.display = 'block';
        statusContainer.style.background = '#eef2ff';
        statusContainer.style.border = '1px solid #c7d2fe';
      }
      if (loadingEl) loadingEl.style.display = 'flex';
      if (successEl) successEl.style.display = 'none';
      if (errorEl) errorEl.style.display = 'none';

      const payload = {
        action: 'create',
        unit_id: unitId,
        name: document.getElementById('newName').value.trim(),
        move_in_date: moveInVal,
        birthday: document.getElementById('newBirthday').value,
        email: document.getElementById('newEmail').value.trim(),
        phone: document.getElementById('newPhone').value.trim(),
        emergency_contact_name: document.getElementById('newEmergencyName').value.trim(),
        emergency_contact_relationship: document.getElementById('newEmergencyRel').value.trim(),
        emergency_contact_phone: document.getElementById('newEmergencyPhone').value.trim(),
        total_occupants: parseInt(document.getElementById('newTotalOccupants').value) || 1,
        adults_count: parseInt(document.getElementById('newAdultsCount').value) || 1,
        kids_count: parseInt(document.getElementById('newKidsCount').value) || 0
      };

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        if (res.status === 403) {
          throw new Error('Your session has expired. Please refresh the page and sign in as Admin again.');
        }
        if (res.status === 504 || res.status === 408) {
          throw new Error('The request timed out. Please check your network and Gmail SMTP connection.');
        }

        let result = null;
        const resText = await res.text();
        try {
          result = JSON.parse(resText);
        } catch (jsonErr) {
          const cleanErr = resText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
          throw new Error(cleanErr.substring(0, 150) || 'Server returned invalid response format.');
        }

        if (res.ok && result.status === 'success') {
          // Smooth transition: hide spinner, display success message
          if (loadingEl) loadingEl.style.display = 'none';
          if (successEl) {
            successEl.style.display = 'flex';
            const sText = document.getElementById('tenantCreationSuccessText');
            if (sText) {
              sText.textContent = result.message || "Tenant account created successfully! Account details have been sent to the tenant's email address.";
            }
          }
          if (statusContainer) {
            statusContainer.style.background = '#ecfdf5';
            statusContainer.style.border = '1px solid #a7f3d0';
          }
          showToast(result.message || "Tenant account created successfully! Account details have been sent to the tenant's email address.", "success");

          // Transition delay so user experiences the success feedback
          setTimeout(() => {
            isSubmittingTenant = false;
            closeAddTenantModal();
            document.getElementById('createTenantForm').reset();
            calculateTotalOccupants();

            if (result.credentials) {
              result.credentials.unit_id = unitId;
              showCredentialsModal(result.credentials);
            } else {
              window.location.href = `admin_dashboard.php?section=sectionDirectory&unit_id=${unitId}`;
            }
          }, 900);

        } else {
          // Re-enable and show error feedback
          isSubmittingTenant = false;
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('btn-loading-state');
            submitBtn.innerHTML = 'Register &amp; Generate Credentials';
          }
          if (cancelBtn) {
            cancelBtn.disabled = false;
          }
          if (loadingEl) loadingEl.style.display = 'none';
          if (errorEl) {
            errorEl.style.display = 'flex';
            const eText = document.getElementById('tenantCreationErrorText');
            if (eText) {
              eText.textContent = result.message || 'Error creating tenant account.';
            }
          }
          if (statusContainer) {
            statusContainer.style.background = '#fef2f2';
            statusContainer.style.border = '1px solid #fecaca';
          }
          showToast(result.message || 'Error creating tenant', 'error');
        }
      } catch (err) {
        console.error('[Tenant Creation Network/Server Error]:', err);
        isSubmittingTenant = false;
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.classList.remove('btn-loading-state');
          submitBtn.innerHTML = 'Register &amp; Generate Credentials';
        }
        if (cancelBtn) {
          cancelBtn.disabled = false;
        }
        if (loadingEl) loadingEl.style.display = 'none';
        if (errorEl) {
          errorEl.style.display = 'flex';
          const eText = document.getElementById('tenantCreationErrorText');
          if (eText) {
            let userMsg = err.message || 'Network or server error creating tenant account.';
            if (typeof navigator !== 'undefined' && navigator.onLine === false) {
              userMsg = 'Network disconnected. Please check your internet connection and try again.';
            } else if (userMsg.toLowerCase().includes('failed to fetch') || userMsg.toLowerCase().includes('networkerror') || userMsg.toLowerCase().includes('load failed')) {
              userMsg = 'Cannot reach server. Please ensure XAMPP (Apache and MySQL) is active and your network connection is stable.';
            }
            eText.textContent = userMsg;
          }
        }
        if (statusContainer) {
          statusContainer.style.background = '#fef2f2';
          statusContainer.style.border = '1px solid #fecaca';
        }
        showToast(err.message || 'Network error creating tenant', 'error');
      }
    });

    function showCredentialsModal(creds) {
      currentGeneratedCreds = creds;
      document.getElementById('credTenantName').textContent = creds.name;
      document.getElementById('credUnitNumber').textContent = creds.unit_number;
      document.getElementById('credUsername').textContent = creds.username;
      document.getElementById('credPassword').textContent = creds.password;
      document.getElementById('tenantCredentialsModal').classList.add('open');
    }

    function closeCredentialsModalAndReload() {
      document.getElementById('tenantCredentialsModal').classList.remove('open');
      const uId = currentGeneratedCreds && currentGeneratedCreds.unit_id ? currentGeneratedCreds.unit_id : null;
      if (uId) {
        window.location.href = `admin_dashboard.php?section=sectionDirectory&unit_id=${uId}`;
      } else {
        window.location.href = `admin_dashboard.php?section=sectionDirectory`;
      }
    }

    function copyCredField(elemId, btn) {
      const val = document.getElementById(elemId).textContent;
      navigator.clipboard.writeText(val).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Copied!';
        setTimeout(() => btn.textContent = orig, 1800);
        showToast('Copied: ' + val, 'success');
      });
    }

    function copyAllCredentials(btn) {
      if (!currentGeneratedCreds) return;
      const text = `TaraRent Resident Account\nResident: ${currentGeneratedCreds.name}\nUnit: ${currentGeneratedCreds.unit_number}\nUsername: ${currentGeneratedCreds.username}\nPassword: ${currentGeneratedCreds.password}`;
      navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ All Copied!';
        setTimeout(() => btn.textContent = orig, 1800);
        showToast('All login details copied to clipboard!', 'success');
      });
    }

    // =========================================================================
    // EXPANDED TENANT DETAILS & DIRECT ID PREVIEW (LANDLORD UI)
    // - Immediately displays uploaded Valid ID image without extra clicks.
    // - STRICT PRIVACY: Live face scan photo is completely excluded.
    // =========================================================================
    function openTenantDetailsModal(t, unitNumber, unitId) {
      if (!t) return;
      document.getElementById('tdAvatar').textContent = (t.name || 'T').charAt(0).toUpperCase();
      document.getElementById('tdName').textContent = t.name || 'Resident';
      document.getElementById('tdUsername').textContent = '@' + (t.username || 'resident');
      document.getElementById('tdUnitBadge').textContent = unitNumber || ('Unit ' + (t.unit_id || ''));
      
      // Verification status badge
      const vBadge = document.getElementById('tdVerificationBadge');
      const vStatus = t.verification_status || 'unverified';
      if (vBadge) {
        if (vStatus === 'verified') {
          vBadge.className = 'clay-badge badge-verified';
          vBadge.textContent = '✓ Verified';
        } else if (vStatus === 'pending') {
          vBadge.className = 'clay-badge badge-pending';
          vBadge.textContent = '⏳ ID Review Pending';
        } else if (vStatus === 'rejected') {
          vBadge.className = 'clay-badge badge-rejected';
          vBadge.textContent = '✕ Rejected';
        } else {
          vBadge.className = 'clay-badge badge-unverified';
          vBadge.textContent = '⚠️ Unverified';
        }
      }

      // Occupant Breakdown
      const adultsVal = parseInt(t.adults_count, 10) || 1;
      const kidsVal = parseInt(t.kids_count, 10) || 0;
      const totalOcc = parseInt(t.total_occupants, 10) || (adultsVal + kidsVal);
      document.getElementById('tdTotalOccupants').textContent = totalOcc;
      document.getElementById('tdAdultsCount').textContent = adultsVal;
      document.getElementById('tdKidsCount').textContent = kidsVal;

      // Personal details
      document.getElementById('tdBirthday').textContent = t.birthday ? new Date(t.birthday.includes('T') ? t.birthday : t.birthday + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';
      document.getElementById('tdMoveIn').textContent = t.move_in_date ? new Date(t.move_in_date.includes('T') ? t.move_in_date : t.move_in_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';
      document.getElementById('tdEmail').textContent = t.email || 'N/A';
      document.getElementById('tdPhone').textContent = t.phone || 'N/A';

      // Emergency contact
      document.getElementById('tdEmergencyName').textContent = t.emergency_contact_name || t.emergency_contact || 'None listed';
      document.getElementById('tdEmergencyRel').textContent = t.emergency_contact_relationship || 'N/A';
      document.getElementById('tdEmergencyPhone').textContent = t.emergency_contact_phone || 'N/A';

      // Uploaded Valid ID Image directly displayed (NO EXTRA CLICK NEEDED)
      // STRICT PRIVACY: live_photo_filename is NEVER rendered or used here.
      const idContainer = document.getElementById('tdIdContainer');
      const idTypeLabel = document.getElementById('tdIdType');
      if (idTypeLabel) idTypeLabel.textContent = t.id_type || 'Government ID';

      if (idContainer) {
        if (t.id_photo_filename) {
          idContainer.innerHTML = `
            <div style="background: rgba(15, 23, 42, 0.03); border: 1.5px solid #cbd5e1; border-radius: 14px; padding: 12px; margin-top: 6px;">
              <img src="uploads/id_verifications/${encodeURIComponent(t.id_photo_filename)}" 
                   alt="Uploaded Valid ID" 
                   style="max-width: 100%; max-height: 280px; object-fit: contain; border-radius: 10px; display: block; margin: 0 auto; box-shadow: 0 4px 14px rgba(0,0,0,0.08);"
                   onerror="this.parentElement.innerHTML='<div style=\\'color: var(--text-muted); font-size: 13px; padding: 20px;\\'>⚠️ ID image file not found on disk.</div>'">
            </div>
          `;
        } else {
          idContainer.innerHTML = `
            <div style="padding: 24px; text-align: center; color: var(--text-muted); background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 12px; margin-top: 6px;">
              <div style="font-size: 32px; margin-bottom: 6px;">📄</div>
              <div style="font-size: 13px; font-weight: 700; color: #0f172a;">No Valid ID uploaded yet</div>
              <div style="font-size: 11.5px; color: var(--text-light); margin-top: 2px;">The resident has not completed ID verification.</div>
            </div>
          `;
        }
      }

      // Rental / Move-out status display
      const isMovedOut = (t.rental_status === 'moved_out');
      const statusBadge = document.getElementById('tdRentalStatusBadge');
      const noticeBox = document.getElementById('tdMovedOutNotice');
      const delBtn = document.getElementById('tdBtnDelete');

      if (statusBadge) {
        if (isMovedOut) {
          statusBadge.className = 'clay-badge';
          statusBadge.style.background = '#fef3c7';
          statusBadge.style.color = '#92400e';
          statusBadge.textContent = 'Moved Out (Pending Settlement)';
        } else {
          statusBadge.className = 'clay-badge badge-paid';
          statusBadge.style.background = '';
          statusBadge.style.color = '';
          statusBadge.textContent = 'Active Resident';
        }
      }

      if (noticeBox) {
        noticeBox.style.display = isMovedOut ? 'block' : 'none';
      }

      // Bind Move-Out / Settlement button
      if (delBtn) {
        delBtn.innerHTML = isMovedOut ? '⚡ Verify Settlement &amp; Archive' : '🚪 Mark as Moved Out';
        delBtn.onclick = () => deleteTenantAccount(t.id, t.name, unitNumber, unitId || t.unit_id, isMovedOut);
      }

      document.getElementById('tenantDetailsModal').classList.add('open');
    }

    function closeTenantDetailsModal() {
      const m = document.getElementById('tenantDetailsModal');
      if (m) m.classList.remove('open');
    }

    // Dynamic UI counter update for unit folder
    function updateUnitTenantCount(unitId) {
      const uIdNum = parseInt(unitId, 10);
      const tbody = document.getElementById(`unit-table-body-${unitId}`);
      const rows = tbody ? tbody.querySelectorAll('.tenant-summary-row') : [];
      const remaining = rows.length;

      const countText = document.getElementById(`unit-count-text-${unitId}`);
      if (countText) countText.textContent = remaining;

      const folderBadge = document.getElementById(`unit-folder-badge-${unitId}`);
      if (folderBadge) {
        folderBadge.className = `clay-badge ${remaining > 0 ? 'badge-paid' : 'badge-low'}`;
        folderBadge.textContent = remaining > 0 ? '🔒 Occupied' : '🟢 Available';
      }

      if (remaining === 0) {
        // Release occupancy status for this unit
        occupiedUnitIds = occupiedUnitIds.filter(id => id !== uIdNum);

        // Update button in unit detail view
        const btnWrapper = document.getElementById(`unit-add-tenant-btn-wrapper-${unitId}`);
        if (btnWrapper) {
          btnWrapper.innerHTML = `
            <button type="button" class="clay-btn clay-btn-primary" onclick="openAddTenantModal(${unitId})">
              <span>➕ Add Tenant to Unit ${unitId}</span>
            </button>
          `;
        }

        // Enable unit in addTenantModal select dropdown
        const selectEl = document.getElementById('newUnitId');
        if (selectEl) {
          const opt = selectEl.querySelector(`option[value="${unitId}"]`);
          if (opt) {
            opt.disabled = false;
            opt.style.color = '';
            opt.textContent = `Unit ${unitId} (Available)`;
          }
        }
      }

      const container = document.getElementById(`unit-tenants-container-${unitId}`);
      if (remaining === 0 && container) {
        container.innerHTML = `
          <div class="clay-card-sm" style="text-align: center; color: var(--text-muted); padding: 54px 24px;">
            <div style="font-size: 46px; margin-bottom: 12px;">📭</div>
            <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin-bottom: 6px;">No Tenants Assigned to this Unit</h3>
            <p style="font-size: 13px; max-width: 440px; margin: 0 auto; line-height: 1.5;">This unit currently has no active residents. Click the <strong>"Add Tenant"</strong> button at the top right to provision a new tenant account.</p>
          </div>
        `;
      }
    }

    // =========================================================================
    // CONTEXTUAL ROUTING: TENANT DELETION
    // When the landlord deletes a tenant account, the system refreshes or stays
    // exactly where they are (inside the specific unit folder).
    // It strictly DOES NOT redirect them back to the main Landlord Dashboard.
    // =========================================================================
    let isDeletingTenant = false;

    async function deleteTenantAccount(tenantId, tenantName, unitNumber, unitId = null, alreadyMovedOut = false) {
      if (isDeletingTenant) return;

      const promptMsg = alreadyMovedOut 
        ? `Verify settlement and archive account for '${tenantName}'? If all bills are paid and verified, this tenant will be archived.`
        : `Are you sure you want to mark '${tenantName}' as Moved Out? If all bills and charges are fully settled, the account will be automatically archived.`;

      const confirmed = confirm(promptMsg);
      if (!confirmed) {
        return;
      }

      isDeletingTenant = true;
      const delBtn = document.getElementById('tdBtnDelete');
      const origBtnContent = delBtn ? delBtn.innerHTML : 'Processing...';

      // Display loading state & disable button
      if (delBtn) {
        delBtn.disabled = true;
        delBtn.style.opacity = '0.75';
        delBtn.style.cursor = 'not-allowed';
        delBtn.innerHTML = `
          <span class="clay-spinner" style="width: 15px; height: 15px; border-width: 2px; vertical-align: middle; margin-right: 6px;"></span>
          <span>Processing move-out &amp; settlement...</span>
        `;
      }

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'mark_moved_out', tenant_id: parseInt(tenantId, 10) })
        });
        const result = await res.json();

        if (res.ok && result.status === 'success' && result.archived) {
          showToast(result.message || 'Tenant account officially archived successfully.');
          closeTenantDetailsModal();

          const resolvedUnitId = unitId || result.unit_id;

          // Smoothly remove summary row from the DOM while remaining in unit folder
          const row = document.getElementById(`tenant-row-${tenantId}`);
          if (row) {
            row.style.transition = 'all 0.3s ease';
            row.style.opacity = '0';
            row.style.transform = 'scale(0.95)';
            setTimeout(() => {
              row.remove();
              if (resolvedUnitId) updateUnitTenantCount(resolvedUnitId);
            }, 300);
          } else {
            // Stay inside the unit folder
            setTimeout(() => {
              window.location.href = `admin_dashboard.php?section=sectionDirectory&unit_id=${resolvedUnitId}`;
            }, 800);
          }
        } else if (result.status === 'pending_settlement') {
          showToast(result.message, 'warning');
          closeTenantDetailsModal();
          setTimeout(() => {
            window.location.reload();
          }, 1200);
        } else {
          showToast(result.message || 'Error processing move-out.', 'error');
        }
      } catch (e) {
        showToast('Network error while processing move-out. Please try again.', 'error');
      } finally {
        isDeletingTenant = false;
        if (delBtn) {
          delBtn.disabled = false;
          delBtn.style.opacity = '1';
          delBtn.style.cursor = 'pointer';
          delBtn.innerHTML = origBtnContent;
        }
      }
    }

    // Restore active section, unit folder, or profile tab on page load
    document.addEventListener('DOMContentLoaded', () => {
      initMoveInDatePicker();

      const moveInInput = document.getElementById('newMoveInDate');
      if (moveInInput) {
        moveInInput.addEventListener('change', function() {
          const todayStr = getManilaTodayStr();
          if (this.value && this.value < todayStr) {
            showToast("Invalid moving-in date. Please select today or a future date.", "error");
            this.value = todayStr;
          }
        });
      }
      const urlParams = new URLSearchParams(window.location.search);
      const targetSection = urlParams.get('section');
      const targetUnitId = urlParams.get('unit_id');
      const targetTab = urlParams.get('tab') || 'accountInfo';

      // Initialize Settings button visibility based on whether Profile section is active
      updateProfileSettingsVisibility(targetSection === 'sectionProfile');

      if (targetSection === 'sectionArchivedTenants') {
        const navBtn = document.getElementById('navTenantDirectory');
        if (navBtn) switchSection('sectionDirectory', navBtn, false);
        switchDirectoryTab('archived');
      } else if (targetSection === 'sectionDirectory') {
        const navBtn = document.getElementById('navTenantDirectory');
        if (navBtn) switchSection('sectionDirectory', navBtn, false);
        if (targetUnitId) {
          openUnitFolder(targetUnitId);
        }
      } else if (targetSection === 'sectionTickets') {
        const navBtn = document.getElementById('navMaintenanceTickets');
        if (navBtn) switchSection('sectionTickets', navBtn, false);
        const targetTicketId = urlParams.get('ticket_id');
        if (targetTicketId) {
          window.openTicketDetailsById(targetTicketId);
        }
      } else if (targetSection === 'sectionPayments') {
        const navBtn = document.getElementById('navPaymentVerification');
        if (navBtn) switchSection('sectionPayments', navBtn, false);
      } else if (targetSection === 'sectionProfile') {
        openProfileView(targetTab, false);
      } else if (targetSection === 'sectionAnalytics') {
        const navBtn = document.getElementById('navLandlordDashboard');
        if (navBtn) switchSection('sectionAnalytics', navBtn, false);
      }
    });

    // =========================================================================
    // UTILITY: SAFE HTML ESCAPER
    // =========================================================================
    function escapeHtml(str) {
      if (str === null || str === undefined) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    // =========================================================================
    // TENANT DIRECTORY TABS: ACTIVE TENANTS VS ARCHIVED TENANTS
    // =========================================================================
    function switchDirectoryTab(tab) {
      const activeBtn = document.getElementById('btnTabActiveDirectory');
      const archivedBtn = document.getElementById('btnTabArchivedDirectory');
      const activeView = document.getElementById('directoryActiveView');
      const archivedView = document.getElementById('directoryArchivedView');

      if (tab === 'archived') {
        if (activeBtn) activeBtn.classList.remove('active');
        if (archivedBtn) archivedBtn.classList.add('active');
        if (activeView) activeView.style.display = 'none';
        if (archivedView) archivedView.style.display = 'block';
        loadArchivedTenants();
      } else {
        if (archivedBtn) archivedBtn.classList.remove('active');
        if (activeBtn) activeBtn.classList.add('active');
        if (archivedView) archivedView.style.display = 'none';
        if (activeView) activeView.style.display = 'block';
      }
    }

    let cachedArchivedTenants = [];
    let isArchivedTenantsLoading = false;

    async function loadArchivedTenants(searchQuery = '') {
      if (isArchivedTenantsLoading) return;
      isArchivedTenantsLoading = true;

      const tbody = document.getElementById('archivedTenantsTableBody');
      const countBadge = document.getElementById('archivedTenantCountBadge');
      const dirBadge = document.getElementById('directoryArchivedCountBadge');

      if (tbody && !searchQuery) {
        tbody.innerHTML = `
          <tr>
            <td colspan="7" style="text-align: center; padding: 36px 14px; color: var(--text-muted); font-size: 13.5px;">
              <span class="clay-spinner" style="width: 18px; height: 18px; border-width: 2px; vertical-align: middle; margin-right: 8px;"></span>
              Loading archived tenant accounts...
            </td>
          </tr>
        `;
      }

      try {
        const url = 'api/tenant_directory_api.php?action=list_archived&search=' + encodeURIComponent(searchQuery);
        const res = await fetch(url);
        const data = await res.json();

        if (res.ok && data.status === 'success') {
          cachedArchivedTenants = data.archived_tenants || [];
          const count = cachedArchivedTenants.length;

          if (countBadge) countBadge.textContent = `${count} Archived Record${count === 1 ? '' : 's'}`;
          if (dirBadge) dirBadge.textContent = count;

          renderArchivedTenantsTable(cachedArchivedTenants);
        } else {
          if (tbody) {
            tbody.innerHTML = `
              <tr>
                <td colspan="7" style="text-align: center; padding: 32px 14px; color: #ef4444; font-size: 13.5px;">
                  Failed to load archived records: ${escapeHtml(data.message || 'Server error')}
                </td>
              </tr>
            `;
          }
        }
      } catch (err) {
        console.error('Error loading archived tenants:', err);
        if (tbody) {
          tbody.innerHTML = `
            <tr>
              <td colspan="7" style="text-align: center; padding: 32px 14px; color: #ef4444; font-size: 13.5px;">
                Network error while loading archived records. Please refresh.
              </td>
            </tr>
          `;
        }
      } finally {
        isArchivedTenantsLoading = false;
      }
    }

    let searchArchivedTimeout = null;
    function filterArchivedTenants(val) {
      if (searchArchivedTimeout) clearTimeout(searchArchivedTimeout);
      searchArchivedTimeout = setTimeout(() => {
        loadArchivedTenants(val.trim());
      }, 300);
    }

    function renderArchivedTenantsTable(tenants) {
      const tbody = document.getElementById('archivedTenantsTableBody');
      if (!tbody) return;

      if (!tenants || tenants.length === 0) {
        tbody.innerHTML = `
          <tr>
            <td colspan="6" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 13.5px;">
              <div style="font-size: 28px; margin-bottom: 8px;">📁</div>
              <strong>No archived tenant accounts found.</strong>
              <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Deleted and moved-out tenant records will appear here with full historical logs.</div>
            </td>
          </tr>
        `;
        return;
      }

      let html = '';
      tenants.forEach(t => {
        const initial = (t.name ? t.name.charAt(0) : 'T').toUpperCase();
        const avatarHtml = t.profile_picture 
          ? `<img src="${escapeHtml(t.profile_picture)}" alt="${escapeHtml(t.name)}" style="width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 1.5px solid #cbd5e1; flex-shrink: 0;">`
          : `<div style="width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #64748b, #475569); color: #fff; font-weight: 700; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">${initial}</div>`;

        html += `
          <tr id="archived-row-${t.id}"
              class="clickable-ticket-row archived-tenant-row"
              onclick="openArchivedTenantDetails(${t.id})"
              title="Click to view full archived tenant details"
              style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);">
            <td style="padding: 14px 16px; font-weight: 700; color: #0f172a; white-space: nowrap;">
              <div style="display: flex; align-items: center; gap: 10px;">
                ${avatarHtml}
                <span>${escapeHtml(t.name)}</span>
              </div>
            </td>
            <td style="padding: 14px 16px; white-space: nowrap;">
              <span class="clay-badge" style="background: #e0e7ff; color: #4338ca; font-weight: 800; font-size: 11.5px; padding: 3px 9px;">
                ${escapeHtml(t.archived_unit_number || 'Unit N/A')}
              </span>
            </td>
            <td style="padding: 14px 16px; color: #334155; font-size: 13px;">${escapeHtml(t.email || '—')}</td>
            <td style="padding: 14px 16px; color: #334155; font-size: 13px; white-space: nowrap;">${escapeHtml(t.phone || '—')}</td>
            <td style="padding: 14px 16px; color: var(--text-muted); font-size: 12.5px; white-space: nowrap;">${escapeHtml(t.formatted_move_in || '—')}</td>
            <td style="padding: 14px 16px; color: #be123c; font-weight: 700; font-size: 12.5px; white-space: nowrap;">
              ${escapeHtml(t.formatted_deleted_at || 'Archived')}
            </td>
          </tr>
        `;
      });

      tbody.innerHTML = html;
    }

    async function openArchivedTenantDetails(tenantId) {
      const modal = document.getElementById('archivedTenantDetailsModal');
      if (!modal) return;

      const tenantIdInput = document.getElementById('archivedModalTenantId');
      if (tenantIdInput) tenantIdInput.value = tenantId;

      // Animate modal open & lock body scroll
      modal.classList.add('open', 'active');
      document.body.style.overflow = 'hidden';

      // Reset loading state for payments list
      const payList = document.getElementById('archivedPaymentsList');
      if (payList) {
        payList.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 24px 14px; color: var(--text-muted);"><span class="clay-spinner" style="width: 16px; height: 16px; border-width: 2px; vertical-align: middle; margin-right: 6px;"></span>Loading payment records...</td></tr>';
      }

      try {
        const res = await fetch(`api/tenant_directory_api.php?action=get_archived_tenant_details&tenant_id=${tenantId}`);
        const data = await res.json();

        if (res.ok && data.status === 'success') {
          const t = data.tenant;
          const payments = data.payments || [];

          // Header
          const nameEl = document.getElementById('archivedModalName');
          const dateEl = document.getElementById('archivedModalDeletedDate');
          const avatarEl = document.getElementById('archivedModalAvatar');
          const usernameBadge = document.getElementById('archivedModalUsernameBadge');

          if (nameEl) nameEl.textContent = t.name || 'Archived Tenant';
          if (dateEl) dateEl.textContent = `Account deleted and archived on ${t.formatted_deleted_at || 'N/A'}`;
          if (usernameBadge) usernameBadge.textContent = t.username ? `@${t.username}` : '@RC000';
          if (avatarEl) {
            if (t.profile_picture) {
              avatarEl.innerHTML = `<img src="${escapeHtml(t.profile_picture)}" alt="${escapeHtml(t.name)}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 14px;">`;
            } else {
              avatarEl.textContent = (t.name ? t.name.charAt(0) : 'T').toUpperCase();
            }
          }

          // Personal Information
          const pName = document.getElementById('archivedPersonalName');
          const pEmail = document.getElementById('archivedPersonalEmail');
          const pPhone = document.getElementById('archivedPersonalPhone');
          const pAddress = document.getElementById('archivedPersonalAddress');
          const pBirthday = document.getElementById('archivedPersonalBirthday');

          if (pName) pName.textContent = t.name || '-';
          if (pEmail) pEmail.textContent = t.email || '—';
          if (pPhone) pPhone.textContent = t.phone || '—';
          if (pAddress) pAddress.textContent = t.complete_address || '—';
          if (pBirthday) pBirthday.textContent = t.formatted_birthday || 'Not provided';

          // Rental Information
          const rUnit = document.getElementById('archivedRentalUnit');
          const rMoveIn = document.getElementById('archivedRentalMoveIn');
          const rRent = document.getElementById('archivedRentalMonthlyRent');
          const rDeposit = document.getElementById('archivedRentalDeposit');
          const rCreatedAt = document.getElementById('archivedRentalCreatedAt');
          const rDeletedAt = document.getElementById('archivedRentalDeletedAt');

          if (rUnit) rUnit.textContent = t.archived_unit_number || 'Unit N/A';
          if (rMoveIn) rMoveIn.textContent = t.formatted_move_in || '—';
          if (rRent) rRent.textContent = t.archived_monthly_rent_formatted || '₱3,000.00 / mo';
          if (rDeposit) rDeposit.textContent = t.advance_deposit_formatted || '₱3,000.00';
          if (rCreatedAt) rCreatedAt.textContent = t.formatted_created_at || '—';
          if (rDeletedAt) rDeletedAt.textContent = t.formatted_deleted_at || 'Archived';

          // Account Information
          const aUser = document.getElementById('archivedAccountUsername');
          const aIdStatus = document.getElementById('archivedAccountIdStatus');

          if (aUser) aUser.textContent = t.username ? `@${t.username}` : '@RC000';
          if (aIdStatus) {
            aIdStatus.textContent = t.id_type ? `${t.id_type} – Verified ID` : 'Verified Account';
          }

          // Payment Records
          const pBadge = document.getElementById('archivedPaymentCountBadge');
          if (pBadge) pBadge.textContent = `${payments.length} Record${payments.length === 1 ? '' : 's'}`;

          if (payList) {
            if (payments.length === 0) {
              payList.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 24px 14px; color: var(--text-muted);">No payment records found for this archived account.</td></tr>';
            } else {
              payList.innerHTML = payments.map(p => {
                const receiptLink = p.receipt_filename 
                  ? `<a href="uploads/receipts/${escapeHtml(p.receipt_filename)}" target="_blank" class="btn-inspect-proof" style="font-size: 11px; padding: 3px 8px; text-decoration: none;">🔍 View Receipt</a>`
                  : '<span style="color: #94a3b8; font-size: 11px;">None</span>';
                const methodStr = p.payment_method || 'GCash / Bank Transfer';
                return `
                  <tr style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);">
                    <td style="padding: 10px 14px; font-weight: 700; color: #0f172a;">${escapeHtml(p.billing_cycle_formatted || '-')}</td>
                    <td style="padding: 10px 14px; font-weight: 600; color: #475569;">${escapeHtml(p.amount_due_formatted || '₱0.00')}</td>
                    <td style="padding: 10px 14px; font-weight: 700; color: #047857;">${escapeHtml(p.amount_paid_formatted || '₱0.00')}</td>
                    <td style="padding: 10px 14px; font-size: 12px; color: var(--text-muted);">${escapeHtml(p.payment_date_formatted || 'Pending')}</td>
                    <td style="padding: 10px 14px; font-size: 12px; color: #334155; font-weight: 600;">${escapeHtml(methodStr)}</td>
                    <td style="padding: 10px 14px;"><span class="clay-badge badge-${escapeHtml(p.status)}">${escapeHtml(String(p.status).toUpperCase())}</span></td>
                    <td style="padding: 10px 14px; text-align: center;">${receiptLink}</td>
                  </tr>
                `;
              }).join('');
            }
          }

        } else {
          showToast(data.message || 'Error fetching archived details', 'error');
        }
      } catch (err) {
        showToast('Error communicating with server', 'error');
      }
    }

    function closeArchivedTenantModal() {
      const modal = document.getElementById('archivedTenantDetailsModal');
      if (modal) {
        modal.classList.remove('open', 'active');
        document.body.style.overflow = '';
      }
    }

    function handleArchivedModalBackdropClick(event) {
      if (event.target === document.getElementById('archivedTenantDetailsModal')) {
        closeArchivedTenantModal();
      }
    }

    async function submitRestoreArchivedAccount() {
      const tenantId = document.getElementById('archivedModalTenantId').value;
      if (!tenantId) return;

      const tenantName = document.getElementById('archivedPersonalName').textContent || 'this tenant';
      if (!confirm(`Are you sure you want to restore '${tenantName}' to the Active Tenants list?`)) {
        return;
      }

      const btn = document.getElementById('btnRestoreArchivedAccount');
      const origHtml = btn.innerHTML;
      btn.innerHTML = '<span>Restoring...</span>';
      btn.disabled = true;

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'restore', tenant_id: parseInt(tenantId, 10) })
        });
        const data = await res.json();
        if (res.ok && data.status === 'success') {
          showToast(data.message || `Tenant '${tenantName}' restored successfully.`);
          closeArchivedTenantModal();
          loadArchivedTenants();
          if (typeof loadTenantsDirectory === 'function') {
            loadTenantsDirectory();
          }
        } else {
          showToast(data.message || 'Failed to restore tenant account.', 'error');
        }
      } catch (err) {
        showToast('Error communicating with server while restoring account.', 'error');
      } finally {
        btn.innerHTML = origHtml;
        btn.disabled = false;
      }
    }

    async function submitPermanentDeleteArchived() {
      const tenantId = document.getElementById('archivedModalTenantId').value;
      if (!tenantId) return;

      const tenantName = document.getElementById('archivedPersonalName').textContent || 'this tenant';
      const confirmFirst = confirm(`⚠️ PERMANENT DELETION WARNING:\n\nAre you sure you want to permanently delete the archived account of '${tenantName}'?\n\nThis action cannot be undone.`);
      if (!confirmFirst) return;

      const confirmSecond = confirm(`Please confirm a second time: Permanently delete '${tenantName}'? All associated account records will be removed.`);
      if (!confirmSecond) return;

      const btn = document.getElementById('btnPermanentDeleteArchived');
      const origHtml = btn.innerHTML;
      btn.innerHTML = 'Deleting...';
      btn.disabled = true;

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'permanent_delete', tenant_id: parseInt(tenantId, 10) })
        });
        const data = await res.json();
        if (res.ok && data.status === 'success') {
          showToast(data.message || `Tenant account '${tenantName}' permanently deleted.`);
          closeArchivedTenantModal();
          loadArchivedTenants();
        } else {
          showToast(data.message || 'Failed to permanently delete tenant account.', 'error');
        }
      } catch (err) {
        showToast('Error communicating with server while deleting account.', 'error');
      } finally {
        btn.innerHTML = origHtml;
        btn.disabled = false;
      }
    }

    // =========================================================================
    // MAINTENANCE TICKETS: SEPARATE ACTIVE & COMPLETED TABS & STATUS WORKFLOW
    // =========================================================================
    function switchTicketTab(tab) {
      const activeBtn = document.getElementById('btnTabActiveTickets');
      const completedBtn = document.getElementById('btnTabCompletedTickets');
      const activePane = document.getElementById('paneActiveTickets');
      const completedPane = document.getElementById('paneCompletedTickets');

      if (tab === 'completed') {
        if (activeBtn) activeBtn.classList.remove('active');
        if (completedBtn) completedBtn.classList.add('active');
        if (activePane) activePane.style.display = 'none';
        if (completedPane) completedPane.style.display = 'block';
      } else {
        if (completedBtn) completedBtn.classList.remove('active');
        if (activeBtn) activeBtn.classList.add('active');
        if (completedPane) completedPane.style.display = 'none';
        if (activePane) activePane.style.display = 'block';
      }
    }

    // Direct Status Update (From table row dropdown or modal)
    async function updateTicketStatus(id, newStatus) {
      const normStatus = (newStatus === 'Done' || newStatus === 'resolved' || newStatus === 'closed') 
        ? 'Done' 
        : ((newStatus === 'On-Going' || newStatus === 'in_progress') ? 'On-Going' : 'Pending');

      try {
        const res = await fetch('api/ticket_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'update_status', ticket_id: parseInt(id, 10), status: normStatus })
        });
        const result = await res.json();
        if (result.status === 'success') {
          showToast(`Ticket #MT-${String(id).padStart(4, '0')} updated to ${normStatus}.`);
          moveTicketRowInDOM(id, normStatus);
        } else {
          showToast(result.message || 'Failed to update ticket status', 'error');
        }
      } catch (e) {
        showToast('Error updating ticket status', 'error');
      }
    }

    // Helper to dynamically move ticket rows between Active and Completed Maintenance tables
    function moveTicketRowInDOM(ticketId, status) {
      const row = document.getElementById('ticket-row-' + ticketId);
      if (!row) return;

      const activeTbody = document.getElementById('activeTicketsTableBody');
      const completedTbody = document.getElementById('completedTicketsTableBody');
      const activeCountBadge = document.getElementById('activeTicketCountBadge');
      const completedCountBadge = document.getElementById('completedTicketCountBadge');

      // 1. Update the status badge inside the row (Column 7)
      const badgeEl = document.getElementById('ticket-badge-' + ticketId);
      if (badgeEl) {
        badgeEl.textContent = status;
        const normClass = (status === 'Done') ? 'badge-done' : ((status === 'On-Going') ? 'badge-ongoing' : 'badge-pending');
        badgeEl.className = 'clay-badge ' + normClass;
      }

      // 2. Update ticket data in onclick if present
      const currentOnClick = row.getAttribute('onclick') || '';
      try {
        const match = currentOnClick.match(/(?:openTicketDetailsModal|openTicketDetailPage)\(([\s\S]*)\)$/);
        if (match && match[1]) {
          const tkData = JSON.parse(match[1]);
          tkData.status = status;
          if (status === 'Done') tkData.resolved_at = new Date().toISOString();
          else tkData.resolved_at = null;
          row.setAttribute('onclick', `openTicketDetailsModal(${JSON.stringify(tkData)})`);
        }
      } catch (err) {}

      // 3. Move row between Active and Completed tables if needed
      if (status === 'Done') {
        // Move to Completed table if not already there
        if (!completedTbody.contains(row)) {
          const emptyCompleted = document.getElementById('emptyCompletedTicketsRow');
          if (emptyCompleted) emptyCompleted.style.display = 'none';

          completedTbody.prepend(row);

          const currentActiveCount = activeTbody.querySelectorAll('.clickable-ticket-row').length;
          const currentCompletedCount = completedTbody.querySelectorAll('.clickable-ticket-row').length;
          if (activeCountBadge) activeCountBadge.textContent = currentActiveCount;
          if (completedCountBadge) completedCountBadge.textContent = currentCompletedCount;

          if (currentActiveCount === 0) {
            const emptyActive = document.getElementById('emptyActiveTicketsRow');
            if (emptyActive) emptyActive.style.display = '';
            else {
              activeTbody.innerHTML = `
                <tr id="emptyActiveTicketsRow">
                  <td colspan="7" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 14px;">
                    <div style="font-size: 32px; margin-bottom: 8px;">🎉</div>
                    <strong>No active or pending maintenance requests.</strong>
                    <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">All reported issues are currently resolved.</div>
                  </td>
                </tr>
              `;
            }
          }
        }
      } else {
        // Status is 'Pending' or 'On-Going', move to Active table if in Completed table
        if (!activeTbody.contains(row)) {
          const emptyActive = document.getElementById('emptyActiveTicketsRow');
          if (emptyActive) emptyActive.style.display = 'none';

          activeTbody.prepend(row);

          const currentActiveCount = activeTbody.querySelectorAll('.clickable-ticket-row').length;
          const currentCompletedCount = completedTbody.querySelectorAll('.clickable-ticket-row').length;
          if (activeCountBadge) activeCountBadge.textContent = currentActiveCount;
          if (completedCountBadge) completedCountBadge.textContent = currentCompletedCount;

          if (currentCompletedCount === 0) {
            const emptyCompleted = document.getElementById('emptyCompletedTicketsRow');
            if (emptyCompleted) emptyCompleted.style.display = '';
            else {
              completedTbody.innerHTML = `
                <tr id="emptyCompletedTicketsRow">
                  <td colspan="7" style="text-align: center; padding: 42px 14px; color: var(--text-muted); font-size: 14px;">
                    <div style="font-size: 32px; margin-bottom: 8px;">📋</div>
                    <strong>No completed maintenance records yet.</strong>
                    <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Resolved tickets will be archived here for historical review.</div>
                  </td>
                </tr>
              `;
            }
          }
        }
      }
    }

    function updateTicket(id, status, cost) {
      updateTicketStatus(id, status);
    }

    // =========================================================================
    // ANIMATED MAINTENANCE TICKET DETAILS MODAL
    // =========================================================================
    function openTicketDetailsModal(tk) {
      if (!tk) return;

      // 1. Identifiers and Header
      const idVal = tk.id;
      const ticketIdInput = document.getElementById('mdlTicketId');
      if (ticketIdInput) ticketIdInput.value = idVal;
      
      const badgeNumber = document.getElementById('mdlTicketNumberBadge');
      if (badgeNumber) badgeNumber.textContent = '#MT-' + String(idVal).padStart(4, '0');

      const titleEl = document.getElementById('mdlTicketTitle');
      if (titleEl) titleEl.textContent = tk.title || 'Maintenance Request';

      // 2. Submission Date
      let dateSub = '-';
      if (tk.created_at) {
        dateSub = new Date(tk.created_at.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      }
      const dateSubEl = document.getElementById('mdlDateSubmitted');
      if (dateSubEl) dateSubEl.textContent = dateSub;

      // 3. Category & Priority Badges
      const catBadge = document.getElementById('mdlCategoryBadge');
      if (catBadge) {
        catBadge.textContent = 'Category: ' + (tk.category || 'General');
        catBadge.className = 'clay-badge badge-' + String(tk.category || 'general').toLowerCase();
      }

      const prioBadge = document.getElementById('mdlPriorityBadge');
      if (prioBadge) {
        const p = String(tk.priority || 'medium').toLowerCase();
        prioBadge.textContent = 'Priority: ' + p.charAt(0).toUpperCase() + p.slice(1);
        prioBadge.className = 'clay-badge badge-' + p;
      }

      // 4. Status Badge & Select
      const normStatus = (tk.status === 'Done' || tk.status === 'resolved' || tk.status === 'closed')
        ? 'Done'
        : ((tk.status === 'On-Going' || tk.status === 'in_progress') ? 'On-Going' : 'Pending');

      const statusBadge = document.getElementById('mdlStatusBadge');
      if (statusBadge) {
        statusBadge.textContent = 'Status: ' + normStatus;
        statusBadge.className = 'clay-badge ' + (normStatus === 'Done' ? 'badge-done' : (normStatus === 'On-Going' ? 'badge-ongoing' : 'badge-pending'));
      }

      const selStatus = document.getElementById('mdlSelectStatus');
      if (selStatus) {
        selStatus.value = normStatus;
      }

      // 5. Tenant Info
      const tenantNameEl = document.getElementById('mdlTenantName');
      if (tenantNameEl) tenantNameEl.textContent = tk.tenant_name || '-';

      const unitBadge = document.getElementById('mdlUnitBadge');
      if (unitBadge) unitBadge.textContent = tk.unit_number || '-';

      const tenantEmailEl = document.getElementById('mdlTenantEmail');
      if (tenantEmailEl) tenantEmailEl.textContent = tk.tenant_email || 'Not on file';

      // 6. Complete Issue Description
      const descEl = document.getElementById('mdlDescription');
      if (descEl) descEl.textContent = tk.description || tk.title || 'No additional description provided.';

      // 7. Resolution Notes
      const notesEl = document.getElementById('mdlResolutionNotes');
      if (notesEl) notesEl.value = tk.resolution_notes || '';

      // 8. Attached Photos Gallery
      const photosContainer = document.getElementById('mdlPhotosContainer');
      const noPhotosNotice = document.getElementById('mdlNoPhotosNotice');
      if (photosContainer) {
        photosContainer.innerHTML = '';
        if (tk.photo_path && tk.photo_path.trim() !== '') {
          if (noPhotosNotice) noPhotosNotice.style.display = 'none';
          photosContainer.style.display = 'flex';
          const pList = tk.photo_path.split(',');
          pList.forEach((pItem, idx) => {
            pItem = pItem.trim();
            if (!pItem) return;
            const card = document.createElement('div');
            card.style.cssText = 'border: 1.5px solid rgba(166,180,204,0.35); border-radius: 10px; overflow: hidden; background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.06); text-align: center; max-width: 160px;';
            card.innerHTML = `
              <a href="${pItem}" target="_blank" title="Click to view full image in a new tab" style="display: block; text-decoration: none;">
                <img src="${pItem}" alt="Ticket Attachment" style="width: 150px; height: 110px; object-fit: cover; display: block;" onerror="this.onerror=null;this.parentElement.innerHTML='<div style=\\'padding:14px;font-size:12px;color:#4338ca;\\'>📷 Photo ' + (${pList.length > 1 ? (idx + 1) : ''}) + '</div>';">
                <div style="font-size: 11.5px; font-weight: 700; color: #4338ca; padding: 7px 6px; background: #f8fafc; border-top: 1px solid rgba(166,180,204,0.2);">
                  🔍 View Photo ${pList.length > 1 ? (idx + 1) : ''}
                </div>
              </a>
            `;
            photosContainer.appendChild(card);
          });
        } else {
          photosContainer.style.display = 'none';
          if (noPhotosNotice) noPhotosNotice.style.display = 'block';
        }
      }

      // 9. Open Modal with Animation & Lock Body Scroll
      const modal = document.getElementById('ticketDetailsModal');
      if (modal) {
        modal.classList.add('open', 'active');
        document.body.style.overflow = 'hidden';
      }
    }

    function closeTicketDetailsModal() {
      const modal = document.getElementById('ticketDetailsModal');
      if (modal) {
        modal.classList.remove('open', 'active');
        document.body.style.overflow = '';
      }
    }

    function handleTicketModalBackdropClick(event) {
      if (event.target === document.getElementById('ticketDetailsModal')) {
        closeTicketDetailsModal();
      }
    }

    // Escape key listener for closing modals
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        if (document.getElementById('ticketDetailsModal')?.classList.contains('open') || document.getElementById('ticketDetailsModal')?.classList.contains('active')) {
          closeTicketDetailsModal();
        }
        if (document.getElementById('archivedTenantDetailsModal')?.classList.contains('open') || document.getElementById('archivedTenantDetailsModal')?.classList.contains('active')) {
          closeArchivedTenantModal();
        }
      }
    });

    // Dedicated Status Update inside Modal
    async function submitModalStatusUpdate() {
      const btn = document.getElementById('btnModalSubmitStatus');
      const id = document.getElementById('mdlTicketId').value;
      const status = document.getElementById('mdlSelectStatus').value;
      const reply = document.getElementById('mdlResolutionNotes').value.trim();

      if (!id) return;

      const origText = btn.innerHTML;
      btn.innerHTML = '<span>Saving...</span>';
      btn.disabled = true;

      try {
        const res = await fetch('api/ticket_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'reply_ticket',
            ticket_id: parseInt(id, 10),
            status: status,
            reply: reply
          })
        });
        const result = await res.json();
        if (result.status === 'success') {
          showToast(result.message || `Ticket #MT-${String(id).padStart(4, '0')} updated to ${status}.`);

          // Update modal status badge if still open
          const statusBadge = document.getElementById('mdlStatusBadge');
          if (statusBadge) {
            statusBadge.textContent = 'Status: ' + status;
            statusBadge.className = 'clay-badge ' + (status === 'Done' ? 'badge-done' : (status === 'On-Going' ? 'badge-ongoing' : 'badge-pending'));
          }

          // Immediately reflect in the main maintenance tables (DOM update and tab transfer)
          moveTicketRowInDOM(id, status);

          // Close modal
          closeTicketDetailsModal();
        } else {
          showToast(result.message || 'Failed to update ticket status', 'error');
        }
      } catch (err) {
        showToast('Error communicating with ticket service', 'error');
      } finally {
        btn.innerHTML = origText;
        btn.disabled = false;
      }
    }

    // Direct lookup helper (used by notifications and URL parameters)
    window.openTicketDetailsById = async function(ticketId) {
      if (typeof switchSection === 'function') {
        const navBtn = document.getElementById('navMaintenanceTickets');
        switchSection('sectionTickets', navBtn, false);
      }
      const row = document.getElementById('ticket-row-' + ticketId);
      if (row) {
        const match = (row.getAttribute('onclick') || '').match(/(?:openTicketDetailsModal|openTicketDetailPage)\(([\s\S]*)\)$/);
        if (match && match[1]) {
          try {
            const tkData = JSON.parse(match[1]);
            openTicketDetailsModal(tkData);
            return;
          } catch(e) {}
        }
      }
      try {
        const res = await fetch(`api/ticket_api.php?ticket_id=${ticketId}`);
        const data = await res.json();
        if (data.status === 'success' && data.ticket) {
          openTicketDetailsModal(data.ticket);
        } else {
          showToast('Ticket not found', 'error');
        }
      } catch(err) {
        showToast('Error retrieving ticket details', 'error');
      }
    };

    // Forwarding functions for backwards compatibility
    window.openTicketDetailPage = openTicketDetailsModal;
    window.closeTicketDetailPage = closeTicketDetailsModal;
    window.submitTicketStatusUpdateFromPage = submitModalStatusUpdate;
    function openTicketReplyModal(id, title, tenant, unit, status, cost, notes) {
      openTicketDetailsModal({
        id: id,
        title: title,
        tenant_name: tenant,
        unit_number: unit,
        status: status,
        resolution_notes: notes,
        category: 'General',
        priority: 'medium',
        description: notes || title
      });
    }
    function closeTicketReplyModal() {
      closeTicketDetailsModal();
    }
    function submitTicketReply() {
      return submitModalStatusUpdate();
    }

    // Rent Due Announcement Modal Controls
    function openAnnouncementModal() {
      document.getElementById('announcementForm').reset();
      document.getElementById('announcementUnitsContainer').style.display = 'none';
      document.getElementById('announcementModal').classList.add('active');
    }

    function closeAnnouncementModal() {
      document.getElementById('announcementModal').classList.remove('active');
    }

    function toggleAnnouncementUnitSelector(val) {
      const container = document.getElementById('announcementUnitsContainer');
      if (container) {
        container.style.display = (val === 'selected') ? 'block' : 'none';
      }
    }

    async function submitAnnouncement() {
      const btn = document.getElementById('btnSubmitAnnouncement');
      const title = document.getElementById('announcementTitle').value.trim();
      const dueDate = document.getElementById('announcementDueDate').value;
      const targetType = document.getElementById('announcementTargetType').value;
      const message = document.getElementById('announcementMessage').value.trim();

      if (!title || !message) {
        showToast('Please fill in title and message.', 'error');
        return;
      }

      let selectedUnits = [];
      if (targetType === 'selected') {
        const checked = document.querySelectorAll('input[name="announcement_units[]"]:checked');
        checked.forEach(cb => selectedUnits.push(parseInt(cb.value)));
        if (selectedUnits.length === 0) {
          showToast('Please select at least one unit.', 'error');
          return;
        }
      }

      const origText = btn.innerHTML;
      btn.innerHTML = 'Sending Emails...';
      btn.disabled = true;

      try {
        const res = await fetch('api/announcement_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            title: title,
            due_date: dueDate,
            target_type: targetType,
            target_units: selectedUnits,
            message: message
          })
        });
        const result = await res.json();
        if (result.status === 'success') {
          showToast(result.message);
          closeAnnouncementModal();
        } else {
          showToast(result.message || 'Failed to send announcement', 'error');
        }
      } catch (e) {
        showToast('Network error while sending announcement', 'error');
      } finally {
        btn.innerHTML = origText;
        btn.disabled = false;
      }
    }

    // =========================================================================
    // PAYMENT VERIFICATION & AUDIT MODAL CONTROLS & FILTERING
    // =========================================================================
    const allPaymentsData = <?= json_encode($payments) ?>;
    let isPaymentVerificationInProgress = false;
    let currentViewingPayment = null;

    // Filter payments ledger table by Month and Year
    function applyPaymentFilters() {
      const selectedMonth = document.getElementById('paymentFilterMonth').value;
      const selectedYear = document.getElementById('paymentFilterYear').value;
      const rows = document.querySelectorAll('.payment-record-row');
      let visibleCount = 0;

      rows.forEach(row => {
        const rowMonth = row.getAttribute('data-month');
        const rowYear = row.getAttribute('data-year');

        const monthMatch = (selectedMonth === 'all') || (rowMonth === selectedMonth);
        const yearMatch = (selectedYear === 'all') || (rowYear === selectedYear);

        if (monthMatch && yearMatch) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      const emptyRow = document.getElementById('paymentNoFilterResultsRow');
      if (emptyRow) {
        emptyRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
      }

      const badge = document.getElementById('paymentFilterCountBadge');
      if (badge) {
        badge.textContent = `Showing ${visibleCount} of ${rows.length} Records`;
      }
    }

    // Reset month & year filters to 'all'
    function resetPaymentFilters() {
      const monthSelect = document.getElementById('paymentFilterMonth');
      const yearSelect = document.getElementById('paymentFilterYear');
      if (monthSelect) monthSelect.value = 'all';
      if (yearSelect) yearSelect.value = 'all';
      applyPaymentFilters();
    }

    // Open Comprehensive Payment Details & Verification Modal (Sections A, B, C)
    function openPaymentDetailsModal(p, defaultDecision = '') {
      if (!p) return;
      currentViewingPayment = p;
      isPaymentVerificationInProgress = false;

      document.getElementById('pdPaymentId').value = p.id;
      document.getElementById('reviewPaymentId').value = p.id;

      // Section A: Billing Information
      const payTypeElem = document.getElementById('pdPaymentType');
      if (payTypeElem) {
        payTypeElem.textContent = p.payment_type || 'Rent';
        payTypeElem.style.color = (p.payment_type === 'Advance Deposit') ? '#6d28d9' : '#0369a1';
      }

      document.getElementById('pdTenantName').textContent = p.tenant_name || 'Resident';
      document.getElementById('pdUnitNumber').textContent = p.unit_number || ('Unit ' + (p.unit_id || ''));

      if (p.billing_cycle) {
        const bDate = new Date(p.billing_cycle.includes('T') ? p.billing_cycle : p.billing_cycle + 'T00:00:00');
        document.getElementById('pdBillingMonth').textContent = bDate.toLocaleDateString('en-US', { month: 'long' });
        document.getElementById('pdBillingYear').textContent = bDate.getFullYear();
      } else {
        document.getElementById('pdBillingMonth').innerHTML = '<span style="color: #92400e; font-style: italic;">To be assigned</span>';
        document.getElementById('pdBillingYear').innerHTML = '<span style="color: #92400e; font-style: italic;">To be assigned</span>';
      }

      if (p.move_in_date) {
        const mDate = new Date(p.move_in_date.includes('T') ? p.move_in_date : p.move_in_date + 'T00:00:00');
        document.getElementById('pdMoveInDate').textContent = mDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      } else {
        document.getElementById('pdMoveInDate').innerHTML = '<span style="color: var(--text-light);">N/A</span>';
      }

      if (p.due_date) {
        const dDate = new Date(p.due_date.includes('T') ? p.due_date : p.due_date + 'T00:00:00');
        document.getElementById('pdDueDate').textContent = dDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      } else {
        document.getElementById('pdDueDate').innerHTML = '<span style="color: #92400e; font-style: italic;">To be assigned</span>';
      }

      // Financials
      const amtDue = p.amount_due !== null ? parseFloat(p.amount_due) : null;
      const amtPaid = parseFloat(p.amount_paid || 0);

      document.getElementById('pdAmountDue').textContent = (amtDue !== null && amtDue > 0)
        ? '₱' + amtDue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : 'Not yet configured';

      document.getElementById('pdAmountPaid').textContent = '₱' + amtPaid.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

      const remBalElem = document.getElementById('pdRemainingBalance');
      if (p.status === 'approved') {
        remBalElem.textContent = '₱0.00';
        remBalElem.style.color = '#047857';
      } else if (amtDue === null || amtDue === 0) {
        remBalElem.textContent = '—';
        remBalElem.style.color = 'var(--text-muted)';
      } else {
        const remaining = Math.max(0, amtDue - amtPaid);
        remBalElem.textContent = '₱' + remaining.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        remBalElem.style.color = remaining > 0 ? '#dc2626' : '#047857';
      }

      // Status Badge
      const statusBadge = document.getElementById('pdStatusBadge');
      if (statusBadge) {
        if (p.status === 'pending_setup') {
          statusBadge.className = 'clay-badge';
          statusBadge.style.cssText = 'background: #fef3c7; color: #92400e; font-weight: 800; border: 1px solid rgba(245, 158, 11, 0.35); font-size: 11px; padding: 4px 10px;';
          statusBadge.textContent = 'AWAITING CONFIGURATION';
        } else if (p.status === 'unpaid') {
          statusBadge.className = 'clay-badge';
          statusBadge.style.cssText = 'background: #fee2e2; color: #991b1b; font-weight: 800; border: 1px solid rgba(239, 68, 68, 0.35); font-size: 11px; padding: 4px 10px;';
          statusBadge.textContent = 'UNPAID';
        } else if (p.status === 'pending') {
          statusBadge.className = 'clay-badge badge-pending';
          statusBadge.style.cssText = '';
          statusBadge.textContent = 'PENDING REVIEW';
        } else if (p.status === 'approved') {
          statusBadge.className = 'clay-badge badge-approved';
          statusBadge.style.cssText = '';
          statusBadge.textContent = 'APPROVED';
        } else if (p.status === 'rejected') {
          statusBadge.className = 'clay-badge badge-rejected';
          statusBadge.style.cssText = '';
          statusBadge.textContent = 'REJECTED';
        } else {
          statusBadge.className = 'clay-badge badge-' + String(p.status || '').toLowerCase();
          statusBadge.style.cssText = '';
          statusBadge.textContent = String(p.status || '').toUpperCase();
        }
      }

      // Section B: Payment Information
      document.getElementById('pdPaymentMethod').textContent = p.payment_method || 'GCash / Bank Transfer';

      let payDateStr = 'Pending submission';
      if (p.payment_date) {
        const pdDate = new Date(p.payment_date.includes('T') ? p.payment_date : p.payment_date + 'T00:00:00');
        payDateStr = pdDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      } else if (p.created_at && p.status !== 'pending_setup') {
        payDateStr = new Date(p.created_at.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      }
      document.getElementById('pdPaymentDate').textContent = payDateStr;

      // Reference Number
      document.getElementById('pdRefNumber').textContent = p.reference_number || ('PAY-' + String(p.id).padStart(5, '0'));

      // Tenant Remarks
      const tenantRemarksElem = document.getElementById('pdTenantRemarks');
      if (tenantRemarksElem) {
        tenantRemarksElem.textContent = p.tenant_remarks || (p.verification_notes && p.status === 'pending' ? p.verification_notes : 'None provided');
      }

      // Receipt Proof
      const receiptInspectBtn = document.getElementById('pdReceiptInspectBtn');
      const receiptContent = document.getElementById('pdReceiptContent');
      const noReceiptMsg = document.getElementById('pdNoReceiptMsg');
      const previewBox = document.getElementById('pdReceiptPreviewBox');
      const previewImage = document.getElementById('pdReceiptImage');

      if (p.receipt_filename && p.receipt_filename.trim() !== '') {
        const fileUrl = 'uploads/receipts/' + encodeURIComponent(p.receipt_filename);
        receiptInspectBtn.href = fileUrl;
        receiptInspectBtn.innerHTML = '📄 Inspect Uploaded Proof (' + p.receipt_filename + ')';
        receiptContent.style.display = 'block';
        noReceiptMsg.style.display = 'none';

        const ext = p.receipt_filename.split('.').pop().toLowerCase();
        if (['png', 'jpg', 'jpeg', 'webp'].includes(ext)) {
          previewImage.src = fileUrl;
          previewBox.style.display = 'block';
        } else {
          previewBox.style.display = 'none';
        }
      } else {
        receiptContent.style.display = 'none';
        noReceiptMsg.style.display = 'block';
        previewBox.style.display = 'none';
      }

      // Section C: Actions & Remarks
      const notesField = document.getElementById('pdVerificationNotes');
      const notesReqLabel = document.getElementById('pdNotesRequiredLabel');
      const setupRow = document.getElementById('pdSetupActionRow');
      const actionRow = document.getElementById('pdActionButtonsRow');
      const processingIndicator = document.getElementById('pdProcessingIndicator');
      const verifiedMeta = document.getElementById('pdVerifiedMeta');

      if (notesReqLabel) notesReqLabel.style.display = 'none';
      if (processingIndicator) processingIndicator.style.display = 'none';

      if (verifiedMeta) {
        verifiedMeta.textContent = p.verified_at ? ((p.verified_by_landlord ? 'Verified on ' : 'Audited on ') + p.verified_at) : '';
      }

      if (defaultDecision === 'approved') {
        notesField.value = 'Payment receipt verified and approved.';
      } else if (defaultDecision === 'rejected') {
        notesField.value = 'Payment proof rejected: ';
        if (notesReqLabel) notesReqLabel.style.display = 'inline';
      } else if (p.verification_notes) {
        notesField.value = p.verification_notes;
      } else {
        notesField.value = '';
      }

      if (p.status === 'pending_setup') {
        if (setupRow) setupRow.style.display = 'block';
        if (actionRow) actionRow.style.display = 'none';
        notesField.disabled = true;
      } else {
        if (setupRow) setupRow.style.display = 'none';
        if (actionRow) actionRow.style.display = 'flex';
        notesField.disabled = false;
      }

      document.getElementById('btnPdApprove').disabled = false;
      document.getElementById('btnPdReject').disabled = false;

      // Open Modal
      document.getElementById('paymentDetailsModal').classList.add('open');
    }

    function closePaymentDetailsModal() {
      const m = document.getElementById('paymentDetailsModal');
      if (m) m.classList.remove('open');
    }

    // Trigger Configure Rent modal directly from details view
    function triggerSetupFromModal() {
      if (!currentViewingPayment) return;
      closePaymentDetailsModal();
      openConfigureRentModal(
        currentViewingPayment.id,
        currentViewingPayment.tenant_name,
        currentViewingPayment.unit_number,
        currentViewingPayment.amount_due,
        currentViewingPayment.billing_cycle,
        currentViewingPayment.due_date
      );
    }

    // Handle Approve / Reject Decision with double-click protection & feedback
    async function handlePaymentVerificationDecision(decision) {
      if (isPaymentVerificationInProgress) return;

      const paymentId = document.getElementById('pdPaymentId').value;
      const notes = document.getElementById('pdVerificationNotes').value.trim();

      if (!paymentId) return;

      if (decision === 'rejected') {
        if (!notes || notes === 'Payment proof rejected:' || notes === 'Payment proof rejected: ') {
          showToast('Please enter an explanation or reason for rejecting this payment proof.', 'error');
          const notesField = document.getElementById('pdVerificationNotes');
          if (notesField) {
            notesField.focus();
            const reqLabel = document.getElementById('pdNotesRequiredLabel');
            if (reqLabel) reqLabel.style.display = 'inline';
          }
          return;
        }
      }

      // Activate Concurrency & Double-Click Protection
      isPaymentVerificationInProgress = true;
      const actionRow = document.getElementById('pdActionButtonsRow');
      const processingIndicator = document.getElementById('pdProcessingIndicator');
      const btnApprove = document.getElementById('btnPdApprove');
      const btnReject = document.getElementById('btnPdReject');

      if (btnApprove) btnApprove.disabled = true;
      if (btnReject) btnReject.disabled = true;
      if (actionRow) actionRow.style.display = 'none';
      if (processingIndicator) {
        document.getElementById('pdProcessingText').textContent = (decision === 'approved')
          ? 'Approving payment receipt & notifying tenant via email...'
          : 'Rejecting payment receipt & notifying tenant via email...';
        processingIndicator.style.display = 'flex';
      }

      try {
        const res = await fetch('api/payment_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'verify_payment',
            payment_id: parseInt(paymentId, 10),
            decision: decision,
            notes: notes
          })
        });

        const result = await res.json();
        if (res.ok && result.status === 'success') {
          showToast(result.message);
          closePaymentDetailsModal();
          setTimeout(() => {
            window.location.href = 'admin_dashboard.php?section=sectionPayments';
          }, 900);
        } else {
          showToast(result.message || 'Error updating payment status', 'error');
          isPaymentVerificationInProgress = false;
          if (processingIndicator) processingIndicator.style.display = 'none';
          if (actionRow) actionRow.style.display = 'flex';
          if (btnApprove) btnApprove.disabled = false;
          if (btnReject) btnReject.disabled = false;
        }
      } catch (err) {
        showToast('Network error verifying payment receipt', 'error');
        isPaymentVerificationInProgress = false;
        if (processingIndicator) processingIndicator.style.display = 'none';
        if (actionRow) actionRow.style.display = 'flex';
        if (btnApprove) btnApprove.disabled = false;
        if (btnReject) btnReject.disabled = false;
      }
    }

    // Backward Compatibility Forwarders
    function openReviewModal(paymentId, tenantName, unitNumber, billingCycle, amountPaid, receiptFilename, defaultDecision = '') {
      const found = allPaymentsData.find(p => p.id == paymentId);
      if (found) {
        openPaymentDetailsModal(found, defaultDecision);
      } else {
        openPaymentDetailsModal({
          id: paymentId,
          tenant_name: tenantName,
          unit_number: unitNumber,
          billing_cycle: billingCycle,
          amount_paid: amountPaid,
          amount_due: amountPaid,
          receipt_filename: receiptFilename,
          status: defaultDecision ? (defaultDecision === 'approved' ? 'approved' : 'rejected') : 'pending'
        }, defaultDecision);
      }
    }

    function closeReviewModal() {
      closePaymentDetailsModal();
    }

    async function submitPaymentReview(decision) {
      await handlePaymentVerificationDecision(decision);
    }

    // Configure Placeholder Rent Modal Controls
    function openConfigureRentModal(paymentId, tenantName, unitNumber, currentRent, currentCycle, currentDueDate) {
      document.getElementById('configPaymentId').value = paymentId;
      document.getElementById('configTenantName').textContent = tenantName || '-';
      document.getElementById('configUnitNumber').textContent = unitNumber || '-';
      
      const rentInput = document.getElementById('configMonthlyRent');
      if (currentRent && parseFloat(currentRent) > 0) {
        rentInput.value = parseFloat(currentRent).toFixed(2);
      } else {
        rentInput.value = '3000.00';
      }

      const cycleInput = document.getElementById('configBillingCycle');
      if (currentCycle && currentCycle.length >= 7) {
        cycleInput.value = currentCycle.substring(0, 7);
      } else {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        cycleInput.value = `${yyyy}-${mm}`;
      }

      const dueDateInput = document.getElementById('configDueDate');
      if (currentDueDate && currentDueDate.length >= 10) {
        dueDateInput.value = currentDueDate;
      } else {
        const valMonth = cycleInput.value;
        dueDateInput.value = `${valMonth}-05`;
      }

      // Auto-update due date when billing month changes if left default
      cycleInput.onchange = function() {
        if (cycleInput.value) {
          dueDateInput.value = `${cycleInput.value}-05`;
        }
      };

      document.getElementById('configureRentModal').classList.add('open');
    }

    function closeConfigureRentModal() {
      document.getElementById('configureRentModal').classList.remove('open');
    }

    async function submitConfigureRent() {
      const paymentId = document.getElementById('configPaymentId').value;
      const rentAmount = document.getElementById('configMonthlyRent').value;
      const cycle = document.getElementById('configBillingCycle').value;
      const dueDate = document.getElementById('configDueDate').value;

      if (!paymentId) return;
      if (!rentAmount || parseFloat(rentAmount) <= 0) {
        showToast('Please enter a valid monthly rent amount greater than 0', 'error');
        return;
      }
      if (!cycle) {
        showToast('Please select a billing month', 'error');
        return;
      }
      if (!dueDate) {
        showToast('Please select a payment due date', 'error');
        return;
      }

      try {
        const res = await fetch('api/payment_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'configure_rent',
            payment_id: parseInt(paymentId, 10),
            monthly_rent: parseFloat(rentAmount),
            billing_cycle: cycle + '-01',
            due_date: dueDate
          })
        });

        const result = await res.json();
        if (res.ok && result.status === 'success') {
          showToast(result.message);
          closeConfigureRentModal();
          setTimeout(() => {
            window.location.href = 'admin_dashboard.php?section=sectionPayments';
          }, 1000);
        } else {
          showToast(result.message || 'Error configuring rent', 'error');
        }
      } catch (err) {
        showToast('Network error configuring rent', 'error');
      }
    }

    // Load Charts & KPIs in ₱
    // Updates the summary widget showing ONLY the total number of residing tenants (NO unit fractions)
    async function loadDashboardAnalytics() {
      try {
        const res = await fetch('api/analytics_api.php');
        const data = await res.json();
        if (data.status === 'success') {
          // 1. Financial KPIs in ₱
          document.getElementById('kpiEfficiency').textContent = `${data.kpis.financials.overall_collection_efficiency_pct.toFixed(1)}%`;
          document.getElementById('kpiOutstanding').textContent = `₱${data.kpis.financials.total_outstanding_balance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
          document.getElementById('kpiRevenue').textContent = `₱${data.kpis.financials.total_revenue_collected.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
          document.getElementById('kpiMaintenance').textContent = `₱${data.kpis.maintenance.total_expenditure.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

          // 2. Simple Dashboard Summary: ONLY total number of tenants (NO unit occupancy fractions)
          const totalTenants = data.kpis.units.total_active_tenants;
          const tenantSummaryEl = document.getElementById('tenantSummaryText');
          if (tenantSummaryEl) {
            tenantSummaryEl.textContent = `${totalTenants} Active Resident${totalTenants === 1 ? '' : 's'}`;
          }

          // 3. Diagnostic Intelligence Alerts
          if (data.diagnostic_alerts && data.diagnostic_alerts.length > 0) {
            const container = document.getElementById('diagnosticAlertsContainer');
            container.innerHTML = '';
            data.diagnostic_alerts.forEach(al => {
              const el = document.createElement('div');
              el.className = `alert-clay alert-clay-${al.severity === 'warning' ? 'warning' : 'info'}`;
              el.innerHTML = `
                <div class="alert-icon-clay">${al.severity === 'warning' ? '⚡' : '📌'}</div>
                <div class="alert-body-clay">
                  <h4>${al.title}</h4>
                  <p>${al.message}</p>
                </div>
              `;
              container.appendChild(el);
            });
          }

          // 4. Render Chart.js widgets in ₱
          RentConnectCharts.initCharts(data);
        }
      } catch (e) {
        console.warn('Analytics loading error:', e);
      }
    }

    document.addEventListener('DOMContentLoaded', loadDashboardAnalytics);
  </script>
  <!-- Real-time Centralized Notification Client Handler -->
  <script src="assets/js/notifications.js"></script>
</body>
</html>
