<?php
/**
 * RentConnect - Landlord Business Intelligence & Management Dashboard
 * Landlord: Lead Dimaculangan (Role: Landlord)
 * Property Architecture: 1 Apartment with Exactly 3 Units (Unlimited Tenants per Unit)
 * Currency: ₱
 */

declare(strict_types=1);

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

// Fetch 3 Units
$unitsStmt = $pdo->query("SELECT * FROM units ORDER BY unit_number ASC");
$units = $unitsStmt->fetchAll();

// Fetch All Active Tenants (Strictly seeded tenants only)
$tenantsStmt = $pdo->query("
    SELECT u.id, u.unit_id, u.username, u.name, u.email, u.phone, 
           u.verification_status, u.id_type, u.id_photo_filename, u.verification_notes, u.id_submitted_at,
           u.birthday, u.emergency_contact, u.emergency_contact_name,
           u.emergency_contact_relationship, u.emergency_contact_phone,
           u.move_in_date, u.total_occupants, u.adults_count, u.kids_count, u.created_at,
           un.unit_number, un.monthly_rent
    FROM users u
    JOIN units un ON u.unit_id = un.id
    WHERE u.role = 'tenant' AND u.is_active = 1
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
    SELECT mt.*, u.name AS tenant_name, un.unit_number
    FROM maintenance_tickets mt
    JOIN users u ON mt.tenant_id = u.id
    JOIN units un ON mt.unit_id = un.id
    ORDER BY mt.created_at DESC
")->fetchAll();

// Fetch Payments Ledger & Pending Reviews
$payments = $pdo->query("
    SELECT p.*, u.name AS tenant_name, un.unit_number
    FROM payments p
    JOIN users u ON p.tenant_id = u.id
    JOIN units un ON p.unit_id = un.id
    ORDER BY p.billing_cycle DESC, p.id DESC
")->fetchAll();

// Count pending payment receipts for manual review badge
$pendingReviewPayments = array_values(array_filter($payments, fn($p) => $p['status'] === 'pending'));
$pendingReviewCount = count($pendingReviewPayments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Landlord Dashboard • RentConnect</title>

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
    .btn-remove-file,
    .btn-inspect-proof,
    .main-content button,
    .main-content .clay-btn,
    .dashboard-header-bar button {
      position: relative;
      z-index: 50;
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
              <img src="assets/images/favicon-64.png" alt="RentConnect Logo" style="width: 28px; height: 28px; object-fit: contain;">
            </div>
            <div class="hide-on-retract">
              <div style="font-size: 18px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px;">RentConnect</div>
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

      <!-- SECTION 2: TENANT ACTIVE DIRECTORY (3 CLAYMORPHIC FOLDERS INTERFACE) -->
      <!-- Requirement: Main view displays 3 claymorphic Folders ("Unit 101", "Unit 102", "Unit 103").
           The landlord must click into a specific unit's folder to view personal info & deletion buttons. -->
      <section id="sectionDirectory" class="app-view-section">
        
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
                              <div style="font-weight: 800; color: #0f172a;"><?= htmlspecialchars($t['name']) ?></div>
                            </div>
                          </td>
                          <td>
                            <span style="font-weight: 700; color: var(--clay-primary-dark); background: #e0e7ff; padding: 3px 8px; border-radius: 6px; font-size: 12.5px;">
                              @<?= htmlspecialchars($t['username']) ?>
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
        </div>

      </section>

      <!-- SECTION 3: MAINTENANCE TICKETS RESOLVER -->
      <section id="sectionTickets" class="app-view-section">
        <div class="dashboard-header-bar">
          <div class="dashboard-title-group">
            <h1>Maintenance Dispatches Across Units</h1>
            <p>Update repair work order statuses and enter actual repair invoice costs</p>
          </div>
        </div>

        <div class="clay-card">
          <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px;">
              <thead>
                <tr style="border-bottom: 2px solid rgba(166, 180, 204, 0.3); color: var(--text-muted); font-weight: 700;">
                  <th style="padding: 12px 14px;">Ticket #</th>
                  <th style="padding: 12px 14px;">Unit</th>
                  <th style="padding: 12px 14px;">Tenant</th>
                  <th style="padding: 12px 14px;">Issue Summary</th>
                  <th style="padding: 12px 14px;">Category</th>
                  <th style="padding: 12px 14px;">Priority</th>
                  <th style="padding: 12px 14px;">Status</th>
                  <th style="padding: 12px 14px;">Actual Cost</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tickets as $tk): ?>
                <tr style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);">
                  <td style="padding: 12px 14px; font-weight: 700;">#<?= $tk['id'] ?></td>
                  <td style="padding: 12px 14px; font-weight: 700; color: #4338ca;"><?= htmlspecialchars($tk['unit_number']) ?></td>
                  <td style="padding: 12px 14px;"><?= htmlspecialchars($tk['tenant_name']) ?></td>
                  <td style="padding: 12px 14px;">
                    <div style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($tk['title']) ?></div>
                    <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars(substr($tk['description'], 0, 60)) ?>...</div>
                    <?php if (!empty($tk['photo_path'])): ?>
                      <div style="margin-top: 4px; display: flex; gap: 4px; flex-wrap: wrap;">
                        <?php 
                          $pList = explode(',', $tk['photo_path']);
                          foreach ($pList as $pIdx => $pItem): 
                            $pItem = trim($pItem);
                            if (!$pItem) continue;
                        ?>
                          <a href="<?= htmlspecialchars($pItem) ?>" target="_blank" class="btn-inspect-proof" style="font-size: 11px; padding: 2px 7px;">
                            📷 Photo <?= count($pList) > 1 ? ($pIdx + 1) : '' ?>
                          </a>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px;"><span class="clay-badge badge-<?= strtolower($tk['category']) ?>"><?= $tk['category'] ?></span></td>
                  <td style="padding: 12px 14px;"><span class="clay-badge badge-<?= $tk['priority'] ?>"><?= strtoupper($tk['priority']) ?></span></td>
                  <td style="padding: 12px 14px;">
                    <select class="clay-select" style="padding: 6px 12px; font-size: 12px;" onchange="updateTicket(<?= $tk['id'] ?>, this.value, <?= (float)($tk['actual_cost'] ?? 0) ?>)">
                      <option value="open" <?= $tk['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                      <option value="in_progress" <?= $tk['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                      <option value="resolved" <?= $tk['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                      <option value="closed" <?= $tk['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                  </td>
                  <td style="padding: 12px 14px; font-weight: 700;">
                    ₱<input type="number" class="clay-input" value="<?= number_format((float)$tk['actual_cost'], 2, '.', '') ?>" step="0.01" style="width: 95px; padding: 4px 8px; font-size: 12.5px; display: inline-block;" onchange="updateTicket(<?= $tk['id'] ?>, '<?= $tk['status'] ?>', this.value)">
                  </td>
                </tr>
                <?php endforeach; ?>
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
                <button type="button" class="btn-verify-approve" onclick="openReviewModal(<?= $pending['id'] ?>, '<?= htmlspecialchars(addslashes($pending['tenant_name'])) ?>', '<?= htmlspecialchars($pending['unit_number']) ?>', '<?= date('F Y', strtotime($pending['billing_cycle'])) ?>', '<?= number_format((float)$pending['amount_paid'], 2) ?>', '<?= htmlspecialchars($pending['receipt_filename'] ?? '') ?>', 'approved')">
                  ✓ Approve
                </button>
                <button type="button" class="btn-verify-reject" onclick="openReviewModal(<?= $pending['id'] ?>, '<?= htmlspecialchars(addslashes($pending['tenant_name'])) ?>', '<?= htmlspecialchars($pending['unit_number']) ?>', '<?= date('F Y', strtotime($pending['billing_cycle'])) ?>', '<?= number_format((float)$pending['amount_paid'], 2) ?>', '<?= htmlspecialchars($pending['receipt_filename'] ?? '') ?>', 'rejected')">
                  ✕ Reject
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Full Payments Ledger Table -->
        <div class="clay-card">
          <div class="widget-header" style="margin-bottom: 16px;">
            <div>
              <h2 class="widget-title" style="font-size: 18px;">All Invoices &amp; Verified Records</h2>
              <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">
                Complete ledger tracking tenant payments, verification status, and audit notes
              </p>
            </div>
          </div>

          <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px;">
              <thead>
                <tr style="border-bottom: 2px solid rgba(166, 180, 204, 0.3); color: var(--text-muted); font-weight: 700;">
                  <th style="padding: 12px 14px;">Billing Month</th>
                  <th style="padding: 12px 14px;">Unit</th>
                  <th style="padding: 12px 14px;">Tenant</th>
                  <th style="padding: 12px 14px;">Amount Due</th>
                  <th style="padding: 12px 14px;">Amount Paid</th>
                  <th style="padding: 12px 14px;">Status</th>
                  <th style="padding: 12px 14px;">Receipt Proof</th>
                  <th style="padding: 12px 14px;">Verification Notes</th>
                  <th style="padding: 12px 14px; text-align: right;">Manual Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($payments as $pm): ?>
                <tr style="border-bottom: 1px solid rgba(166, 180, 204, 0.15);">
                  <td style="padding: 12px 14px; font-weight: 700;"><?= date('F Y', strtotime($pm['billing_cycle'])) ?></td>
                  <td style="padding: 12px 14px; font-weight: 700; color: #4338ca;"><?= htmlspecialchars($pm['unit_number']) ?></td>
                  <td style="padding: 12px 14px;"><?= htmlspecialchars($pm['tenant_name']) ?></td>
                  <td style="padding: 12px 14px; font-weight: 700;">₱<?= number_format((float)$pm['amount_due'], 2) ?></td>
                  <td style="padding: 12px 14px; font-weight: 700; color: #047857;">₱<?= number_format((float)$pm['amount_paid'], 2) ?></td>
                  <td style="padding: 12px 14px;">
                    <span class="clay-badge badge-<?= strtolower($pm['status']) ?>"><?= strtoupper($pm['status']) ?></span>
                  </td>
                  <td style="padding: 12px 14px;">
                    <?php if ($pm['receipt_filename']): ?>
                      <a href="uploads/receipts/<?= htmlspecialchars($pm['receipt_filename']) ?>" target="_blank" class="btn-inspect-proof">
                        📄 View Proof
                      </a>
                    <?php else: ?>
                      <span style="font-size: 12px; color: var(--text-light);">No receipt</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px; font-size: 12px; color: var(--text-muted); max-width: 220px;">
                    <?= htmlspecialchars($pm['verification_notes'] ?: ($pm['verified_by_landlord'] ? 'Verified & approved' : '—')) ?>
                  </td>
                  <td style="padding: 12px 14px; text-align: right;">
                    <?php if ($pm['status'] === 'pending'): ?>
                      <button type="button" class="clay-btn clay-btn-primary" style="padding: 6px 14px; font-size: 11.5px;" onclick="openReviewModal(<?= $pm['id'] ?>, '<?= htmlspecialchars(addslashes($pm['tenant_name'])) ?>', '<?= htmlspecialchars($pm['unit_number']) ?>', '<?= date('F Y', strtotime($pm['billing_cycle'])) ?>', '<?= number_format((float)$pm['amount_paid'], 2) ?>', '<?= htmlspecialchars($pm['receipt_filename'] ?? '') ?>')">
                        Review Proof
                      </button>
                    <?php elseif ($pm['status'] === 'approved'): ?>
                      <button type="button" class="clay-btn clay-btn-secondary" style="padding: 6px 14px; font-size: 11.5px;" onclick="openReviewModal(<?= $pm['id'] ?>, '<?= htmlspecialchars(addslashes($pm['tenant_name'])) ?>', '<?= htmlspecialchars($pm['unit_number']) ?>', '<?= date('F Y', strtotime($pm['billing_cycle'])) ?>', '<?= number_format((float)$pm['amount_paid'], 2) ?>', '<?= htmlspecialchars($pm['receipt_filename'] ?? '') ?>', 'approved')">
                        Re-audit
                      </button>
                    <?php elseif ($pm['status'] === 'rejected'): ?>
                      <button type="button" class="clay-btn clay-btn-secondary" style="padding: 6px 14px; font-size: 11.5px;" onclick="openReviewModal(<?= $pm['id'] ?>, '<?= htmlspecialchars(addslashes($pm['tenant_name'])) ?>', '<?= htmlspecialchars($pm['unit_number']) ?>', '<?= date('F Y', strtotime($pm['billing_cycle'])) ?>', '<?= number_format((float)$pm['amount_paid'], 2) ?>', '<?= htmlspecialchars($pm['receipt_filename'] ?? '') ?>', 'rejected')">
                        Re-evaluate
                      </button>
                    <?php else: ?>
                      <span style="font-size: 12px; color: var(--text-muted);">Awaiting proof</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
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
                    <span class="detail-value" style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($user['email'] ?? 'admin@rentconnect.ph') ?></span>
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
       MODAL: MANUAL PAYMENT VERIFICATION & PROOF INSPECTION
       ===================================================================== -->
  <div class="modal-backdrop" id="reviewPaymentModal">
    <div class="clay-modal">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
          <h2 style="font-size: 20px; font-weight: 800; color: #0f172a;">Verify Payment Receipt</h2>
          <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">Inspect tenant proof and record verification status</p>
        </div>
        <button type="button" class="btn-remove-file" onclick="closeReviewModal()">✕</button>
      </div>

      <input type="hidden" id="reviewPaymentId" value="">

      <!-- Payment Summary Details -->
      <div style="background: var(--clay-input-bg); border-radius: var(--radius-md); padding: 14px 18px; box-shadow: var(--clay-sunken-shadow); margin-bottom: 18px;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;">
          <div><span style="color: var(--text-muted);">Resident:</span> <strong id="reviewTenantName" style="color: #0f172a;">-</strong></div>
          <div><span style="color: var(--text-muted);">Apartment Unit:</span> <strong id="reviewUnitNumber" style="color: #4338ca;">-</strong></div>
          <div><span style="color: var(--text-muted);">Billing Cycle:</span> <strong id="reviewBillingCycle">-</strong></div>
          <div><span style="color: var(--text-muted);">Submitted Amount:</span> <strong id="reviewAmountPaid" style="color: #047857;">-</strong></div>
        </div>
      </div>

      <!-- Proof Inspection Link / Thumbnail -->
      <div class="form-group" style="margin-bottom: 18px;">
        <label class="form-label">Receipt File Proof</label>
        <div id="reviewProofContainer" style="padding: 12px; background: var(--clay-card-bg); border-radius: var(--radius-md); border: 1px solid var(--clay-border); text-align: center;">
          <a id="reviewProofLink" href="#" target="_blank" class="btn-inspect-proof" style="font-size: 13px; padding: 8px 16px;">
            📄 Click to Inspect Receipt in New Tab
          </a>
        </div>
      </div>

      <!-- Landlord Verification Feedback Notes -->
      <div class="form-group" style="margin-bottom: 20px;">
        <label class="form-label" for="reviewNotes">Verification Feedback / Notes</label>
        <textarea id="reviewNotes" class="clay-textarea" placeholder="Optional notes for tenant (e.g. Bank transfer reference confirmed, or partial payment detected)..." rows="3"></textarea>
      </div>

      <!-- Dual Action Buttons: Approve vs Reject -->
      <div style="display: flex; gap: 12px;">
        <button type="button" class="btn-verify-reject" style="flex: 1; padding: 12px;" onclick="submitPaymentReview('rejected')">
          ✕ Reject Receipt
        </button>
        <button type="button" class="btn-verify-approve" style="flex: 1; padding: 12px;" onclick="submitPaymentReview('approved')">
          ✓ Approve &amp; Verify Payment
        </button>
      </div>
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

        <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 14px;">
          <div class="form-group">
            <label class="form-label" for="newName">Full Name <span class="req">*</span></label>
            <input type="text" id="newName" name="name" class="clay-input" placeholder="e.g. Michael Jordan" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="newBirthday">Birthday <span class="req">*</span></label>
            <input type="date" id="newBirthday" name="birthday" class="clay-input" required>
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

        <div style="display: flex; gap: 12px;">
          <button type="button" class="clay-btn clay-btn-secondary" style="flex: 1;" onclick="closeAddTenantModal()">Cancel</button>
          <button type="submit" class="clay-btn clay-btn-primary" style="flex: 2;">Register &amp; Generate Credentials</button>
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
        <div style="font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
          <span>📋</span> Personal &amp; Contact Information
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;">
          <div><strong style="color: var(--text-muted);">Birthday:</strong> <span id="tdBirthday" style="font-weight: 700; color: #0f172a;">N/A</span></div>
          <div><strong style="color: var(--text-muted);">Moved In:</strong> <span id="tdMoveIn" style="font-weight: 700; color: #0f172a;">N/A</span></div>
          <div style="grid-column: 1 / -1;"><strong style="color: var(--text-muted);">Email Address:</strong> <span id="tdEmail" style="font-weight: 700; color: #0f172a;">email@example.com</span></div>
          <div style="grid-column: 1 / -1;"><strong style="color: var(--text-muted);">Phone Number:</strong> <span id="tdPhone" style="font-weight: 700; color: #0f172a;">+63 900 000 0000</span></div>
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
      calculateTotalOccupants();
      document.getElementById('addTenantModal').classList.add('open');
    }
    function closeAddTenantModal() {
      document.getElementById('addTenantModal').classList.remove('open');
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

    // Handle Tenant Creation with Auto-Generated Credentials
    let currentGeneratedCreds = null;
    document.getElementById('createTenantForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const unitId = parseInt(document.getElementById('newUnitId').value, 10);
      if (occupiedUnitIds.includes(unitId)) {
        showToast("This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.", "error");
        return;
      }
      const payload = {
        action: 'create',
        unit_id: unitId,
        name: document.getElementById('newName').value.trim(),
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
        const result = await res.json();
        if (res.ok && result.status === 'success') {
          showToast(result.message);
          closeAddTenantModal();
          document.getElementById('createTenantForm').reset();
          calculateTotalOccupants();
          
          if (result.credentials) {
            result.credentials.unit_id = unitId;
            showCredentialsModal(result.credentials);
          } else {
            setTimeout(() => {
              window.location.href = `admin_dashboard.php?section=sectionDirectory&unit_id=${unitId}`;
            }, 1000);
          }
        } else {
          showToast(result.message || 'Error creating tenant', 'error');
        }
      } catch (err) {
        showToast('Network error creating tenant', 'error');
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
      const text = `RentConnect Resident Account\nResident: ${currentGeneratedCreds.name}\nUnit: ${currentGeneratedCreds.unit_number}\nUsername: ${currentGeneratedCreds.username}\nPassword: ${currentGeneratedCreds.password}`;
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
      document.getElementById('tdBirthday').textContent = t.birthday ? new Date(t.birthday).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';
      document.getElementById('tdMoveIn').textContent = t.move_in_date ? new Date(t.move_in_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '2025';
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

      // Bind Delete Account button
      const delBtn = document.getElementById('tdBtnDelete');
      if (delBtn) {
        delBtn.onclick = () => deleteTenantAccount(t.id, t.name, unitNumber, unitId || t.unit_id);
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
    async function deleteTenantAccount(tenantId, tenantName, unitNumber, unitId = null) {
      if (!confirm(`Are you sure you want to delete the account for ${tenantName} (${unitNumber})? This will remove their credentials as they have moved out.`)) {
        return;
      }

      try {
        const res = await fetch('api/tenant_directory_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete', tenant_id: tenantId })
        });
        const result = await res.json();
        if (res.ok && result.status === 'success') {
          showToast(result.message);
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
            window.location.href = `admin_dashboard.php?section=sectionDirectory&unit_id=${resolvedUnitId}`;
          }
        } else {
          showToast(result.message || 'Error deleting tenant', 'error');
        }
      } catch (e) {
        showToast('Network error deleting tenant', 'error');
      }
    }

    // Restore active section, unit folder, or profile tab on page load
    document.addEventListener('DOMContentLoaded', () => {
      const urlParams = new URLSearchParams(window.location.search);
      const targetSection = urlParams.get('section');
      const targetUnitId = urlParams.get('unit_id');
      const targetTab = urlParams.get('tab') || 'accountInfo';

      // Initialize Settings button visibility based on whether Profile section is active
      updateProfileSettingsVisibility(targetSection === 'sectionProfile');

      if (targetSection === 'sectionDirectory') {
        const navBtn = document.getElementById('navTenantDirectory');
        if (navBtn) switchSection('sectionDirectory', navBtn, false);
        if (targetUnitId) {
          openUnitFolder(targetUnitId);
        }
      } else if (targetSection === 'sectionTickets') {
        const navBtn = document.getElementById('navMaintenanceTickets');
        if (navBtn) switchSection('sectionTickets', navBtn, false);
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

    // Update Ticket
    async function updateTicket(id, status, cost) {
      try {
        const res = await fetch('api/ticket_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'update_ticket', ticket_id: id, status: status, actual_cost: parseFloat(cost) || 0 })
        });
        const result = await res.json();
        if (result.status === 'success') {
          showToast(result.message);
          if (window.history && window.history.replaceState) {
            history.replaceState(null, '', 'admin_dashboard.php?section=sectionTickets');
          }
        }
      } catch (e) {
        showToast('Error updating ticket', 'error');
      }
    }

    // Payment Review Modal Controls
    function openReviewModal(paymentId, tenantName, unitNumber, billingCycle, amountPaid, receiptFilename, defaultDecision = '') {
      document.getElementById('reviewPaymentId').value = paymentId;
      document.getElementById('reviewTenantName').textContent = tenantName;
      document.getElementById('reviewUnitNumber').textContent = unitNumber;
      document.getElementById('reviewBillingCycle').textContent = billingCycle;
      document.getElementById('reviewAmountPaid').textContent = '₱' + amountPaid;
      
      const proofLink = document.getElementById('reviewProofLink');
      const proofContainer = document.getElementById('reviewProofContainer');
      if (receiptFilename && receiptFilename.trim() !== '') {
        proofLink.href = 'uploads/receipts/' + encodeURIComponent(receiptFilename);
        proofLink.style.display = 'inline-flex';
        proofLink.innerHTML = '📄 Inspect Uploaded Receipt (' + receiptFilename + ')';
      } else {
        proofLink.style.display = 'none';
        proofContainer.innerHTML = '<span style="font-size: 13px; color: var(--text-muted);">No receipt file uploaded by tenant.</span>';
      }

      const notesField = document.getElementById('reviewNotes');
      if (defaultDecision === 'approved') {
        notesField.value = 'Payment receipt verified and approved.';
      } else if (defaultDecision === 'rejected') {
        notesField.value = 'Payment proof rejected: ';
      } else {
        notesField.value = '';
      }

      document.getElementById('reviewPaymentModal').classList.add('open');
    }

    function closeReviewModal() {
      document.getElementById('reviewPaymentModal').classList.remove('open');
    }

    async function submitPaymentReview(decision) {
      const paymentId = document.getElementById('reviewPaymentId').value;
      const notes = document.getElementById('reviewNotes').value.trim();

      if (!paymentId) return;

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
          closeReviewModal();
          // Universal Contextual Routing: Stay in place on Payment Verification
          setTimeout(() => {
            window.location.href = 'admin_dashboard.php?section=sectionPayments';
          }, 1000);
        } else {
          showToast(result.message || 'Error updating payment status', 'error');
        }
      } catch (err) {
        showToast('Network error verifying payment', 'error');
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
