<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

echo "=== STARTING INTEGRATION TEST FOR MAINTENANCE TICKETS & TENANT DIRECTORY ===\n\n";

$pdo = Database::getConnection();

// --- TEST 1: TICKET LIFECYCLE & STATUS WORKFLOW ---
echo "[1] Testing Ticket Status Transitions...\n";

// Insert a test ticket with 'Pending'
$stmt = $pdo->prepare("
    INSERT INTO maintenance_tickets (tenant_id, unit_id, title, description, category, priority, status, created_at)
    VALUES (2, 1, 'Test Leaky Pipe', 'Faucet dripping in bathroom', 'Plumbing', 'medium', 'Pending', NOW())
");
$stmt->execute();
$ticketId = (int)$pdo->lastInsertId();
echo "  -> Created test ticket #MT-{$ticketId} with status 'Pending'.\n";

// Verify initial state
$checkStmt = $pdo->prepare("SELECT status, resolved_at FROM maintenance_tickets WHERE id = :id");
$checkStmt->execute([':id' => $ticketId]);
$row = $checkStmt->fetch(PDO::FETCH_ASSOC);
assert($row['status'] === 'Pending', "Expected status 'Pending', got: " . $row['status']);
assert(empty($row['resolved_at']), "Expected resolved_at to be NULL for Pending");
echo "  -> PASS: Verified status is 'Pending' and resolved_at is NULL.\n";

// Simulate owner updating status to 'On-Going' via API logic
$updateStmt = $pdo->prepare("
    UPDATE maintenance_tickets 
    SET status = 'On-Going', 
        resolved_at = CASE WHEN 'On-Going' IN ('Done', 'resolved', 'closed') THEN NOW() ELSE NULL END
    WHERE id = :id
");
$updateStmt->execute([':id' => $ticketId]);
$checkStmt->execute([':id' => $ticketId]);
$row = $checkStmt->fetch(PDO::FETCH_ASSOC);
assert($row['status'] === 'On-Going', "Expected status 'On-Going', got: " . $row['status']);
assert(empty($row['resolved_at']), "Expected resolved_at to be NULL for On-Going");
echo "  -> PASS: Verified status transitioned to 'On-Going'.\n";

// Simulate owner completing repair to 'Done'
$updateStmt2 = $pdo->prepare("
    UPDATE maintenance_tickets 
    SET status = 'Done', 
        resolved_at = CASE WHEN 'Done' IN ('Done', 'resolved', 'closed') THEN NOW() ELSE NULL END
    WHERE id = :id
");
$updateStmt2->execute([':id' => $ticketId]);
$checkStmt->execute([':id' => $ticketId]);
$row = $checkStmt->fetch(PDO::FETCH_ASSOC);
assert($row['status'] === 'Done', "Expected status 'Done', got: " . $row['status']);
assert(!empty($row['resolved_at']), "Expected resolved_at to be populated for Done");
echo "  -> PASS: Verified status transitioned to 'Done' and resolved_at timestamp set.\n";

// Simulate reopening back to 'On-Going'
$updateStmt3 = $pdo->prepare("
    UPDATE maintenance_tickets 
    SET status = 'On-Going', 
        resolved_at = CASE WHEN 'On-Going' IN ('Done', 'resolved', 'closed') THEN NOW() ELSE NULL END
    WHERE id = :id
");
$updateStmt3->execute([':id' => $ticketId]);
$checkStmt->execute([':id' => $ticketId]);
$row = $checkStmt->fetch(PDO::FETCH_ASSOC);
assert($row['status'] === 'On-Going', "Expected status 'On-Going', got: " . $row['status']);
assert(empty($row['resolved_at']), "Expected resolved_at to be reset to NULL");
echo "  -> PASS: Verified status reopened to 'On-Going' and resolved_at cleared.\n";

// Cleanup test ticket
$pdo->prepare("DELETE FROM maintenance_tickets WHERE id = :id")->execute([':id' => $ticketId]);
echo "  -> Cleaned up test ticket.\n\n";

// --- TEST 2: TENANT DIRECTORY ARCHIVED API ENDPOINTS ---
echo "[2] Testing Archived Tenants API Query Logic...\n";
$archivedStmt = $pdo->prepare("
    SELECT u.id, u.name, u.username, u.email, u.phone, u.birthday, 
           u.archived_unit_number, u.archived_monthly_rent, u.archived_spaces_text,
           u.move_in_date, u.deleted_at, u.created_at, u.profile_picture,
           (SELECT COUNT(*) FROM payments WHERE tenant_id = u.id) AS total_payments,
           (SELECT COUNT(*) FROM maintenance_tickets WHERE tenant_id = u.id) AS total_tickets,
           (SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE tenant_id = u.id AND status = 'approved') AS total_paid
    FROM users u
    WHERE u.role = 'tenant' AND (u.is_archived = 1 OR u.deleted_at IS NOT NULL)
    ORDER BY u.deleted_at DESC, u.id DESC
");
$archivedStmt->execute();
$archivedRecords = $archivedStmt->fetchAll(PDO::FETCH_ASSOC);
echo "  -> Found " . count($archivedRecords) . " archived records in database.\n";
assert(count($archivedRecords) > 0, "Expected at least 1 archived tenant");
$firstArchived = $archivedRecords[0];
echo "  -> Sample record: {$firstArchived['name']} (ID: {$firstArchived['id']}, Unit: {$firstArchived['archived_unit_number']})\n";
assert(!empty($firstArchived['deleted_at']), "Expected deleted_at timestamp");
echo "  -> PASS: Verified archived tenant list retrieval.\n\n";

// --- TEST 3: INSPECT HTML STRUCTURE OF ADMIN_DASHBOARD.PHP ---
echo "[3] Inspecting HTML & DOM Components in admin_dashboard.php...\n";
$adminHtml = file_get_contents(__DIR__ . '/../admin_dashboard.php');

// A. Check Maintenance Tickets Tab
assert(strpos($adminHtml, 'id="btnTabActiveTickets"') !== false, "Active Tickets Tab button missing");
assert(strpos($adminHtml, 'id="btnTabCompletedTickets"') !== false, "Completed Tickets Tab button missing");
assert(strpos($adminHtml, 'id="paneActiveTickets"') !== false, "Active Tickets Pane missing");
assert(strpos($adminHtml, 'id="paneCompletedTickets"') !== false, "Completed Tickets Pane missing");
assert(strpos($adminHtml, 'id="activeTicketCountBadge"') !== false, "Active Tickets Count Badge missing");
assert(strpos($adminHtml, 'id="completedTicketCountBadge"') !== false, "Completed Tickets Count Badge missing");
assert(strpos($adminHtml, 'updateTicketStatus(') !== false, "updateTicketStatus JS call missing");
assert(strpos($adminHtml, 'openTicketDetailsModal(') !== false, "openTicketDetailsModal JS call missing");
echo "  -> PASS: Verified Maintenance Tickets active and completed tabs & counts.\n";

// B. Check Repair Expense is completely removed from Tickets section and form
assert(strpos($adminHtml, 'id="replyTicketCost"') === false, "replyTicketCost must NOT be present in modal");
assert(strpos($adminHtml, '<th>Repair Expense') === false, "Repair Expense table header must NOT be present");
assert(strpos($adminHtml, '<th>Actual Cost') === false, "Actual Cost table header must NOT be present in tickets");
echo "  -> PASS: Verified Repair Expense / Actual Cost is completely removed from Maintenance Tickets.\n";

// B2. Check 7-Column Simplified Table and Removal of Action Column
$expectedHeaders = [
    'Ticket Number',
    'Tenant Name',
    'Unit',
    'Issue Description',
    'Date Submitted',
    'Priority',
    'Status'
];
foreach ($expectedHeaders as $hdr) {
    assert(strpos($adminHtml, '<th style="padding: 14px 16px;">' . $hdr . '</th>') !== false, "Header missing: " . $hdr);
}

// Ensure Action column is completely gone from the maintenance tables
assert(strpos($adminHtml, '<th style="padding: 14px 16px; text-align: center;">Action</th>') === false, "Action column header must NOT be present in maintenance tables");
assert(strpos($adminHtml, '💬 View Details') === false, "View Details action buttons must NOT be present inside table cells");
assert(strpos($adminHtml, 'ticket-status-select') === false, "Inline ticket-status-select dropdown must NOT be present inside table cells");

// Ensure entire row is clickable
assert(strpos($adminHtml, 'class="clickable-ticket-row ticket-record-row"') !== false, "Clickable row class missing");
assert(strpos($adminHtml, 'cursor: pointer !important;') !== false, "Cursor pointer missing for ticket row");

// Ensure modal detailed view is used with animated transitions (instead of a separate page)
assert(strpos($adminHtml, 'id="ticketDetailsModal"') !== false, "ticketDetailsModal missing in admin_dashboard.php");
assert(strpos($adminHtml, 'ticket-details-modal-box') !== false, "ticket-details-modal-box missing in admin_dashboard.php");
assert(strpos($adminHtml, 'openTicketDetailsModal(') !== false, "openTicketDetailsModal call missing");
assert(strpos($adminHtml, 'closeTicketDetailsModal(') !== false, "closeTicketDetailsModal call missing");
assert(strpos($adminHtml, 'submitModalStatusUpdate(') !== false, "submitModalStatusUpdate call missing");
assert(strpos($adminHtml, 'handleTicketModalBackdropClick(') !== false, "handleTicketModalBackdropClick call missing");
assert(strpos($adminHtml, 'window.openTicketDetailsById') !== false, "window.openTicketDetailsById missing");

// Ensure ticket details modal contains all required elements
$modalElements = [
    'id="mdlTicketNumberBadge"',
    'id="mdlTicketTitle"',
    'id="mdlDateSubmitted"',
    'id="mdlCategoryBadge"',
    'id="mdlPriorityBadge"',
    'id="mdlStatusBadge"',
    'id="mdlTenantName"',
    'id="mdlUnitBadge"',
    'id="mdlTenantEmail"',
    'id="mdlDescription"',
    'id="mdlPhotosContainer"',
    'id="mdlNoPhotosNotice"',
    'id="formModalStatusUpdate"',
    'id="mdlSelectStatus"',
    'id="mdlResolutionNotes"',
    'id="btnModalSubmitStatus"'
];
foreach ($modalElements as $el) {
    assert(strpos($adminHtml, $el) !== false, "Modal element missing: " . $el);
}

// Verify modal animation styles exist
assert(strpos($adminHtml, 'backdrop-filter: blur(6px);') !== false, "Backdrop blur filter missing for ticketDetailsModal");
assert(strpos($adminHtml, 'transform: scale(0.92)') !== false, "Scale transition missing for ticketDetailsModal");
assert(strpos($adminHtml, 'transform: scale(1)') !== false, "Scale active state missing for ticketDetailsModal");
echo "  -> PASS: Verified 7-column simplified layout, Action column removal, clickable rows, and animated Maintenance Details Modal.\n";

// C. Check Tenant Directory Tabs
assert(strpos($adminHtml, 'id="btnTabActiveDirectory"') !== false, "Active Directory Tab button missing");
assert(strpos($adminHtml, 'id="btnTabArchivedDirectory"') !== false, "Archived Directory Tab button missing");
assert(strpos($adminHtml, 'id="directoryActiveView"') !== false, "directoryActiveView wrapper missing");
assert(strpos($adminHtml, 'id="directoryArchivedView"') !== false, "directoryArchivedView wrapper missing");
assert(strpos($adminHtml, 'id="archivedTenantsTableBody"') !== false, "archivedTenantsTableBody missing");
assert(strpos($adminHtml, 'id="archivedTenantDetailsModal"') !== false, "archivedTenantDetailsModal missing");
echo "  -> PASS: Verified Tenant Directory Active and Archived tabs and dossier modal.\n";

// D. Check Sidebar - Archived Tenants separate button removed
assert(strpos($adminHtml, 'id="navArchivedTenants"') === false, "navArchivedTenants button must NOT be present in sidebar");
echo "  -> PASS: Verified Archived Tenants is removed as a separate sidebar item.\n\n";

// --- TEST 4: INSPECT TENANT_PORTAL.PHP STATUS COMPATIBILITY ---
echo "[4] Inspecting tenant_portal.php Status Display...\n";
$tenantHtml = file_get_contents(__DIR__ . '/../tenant_portal.php');
assert(strpos($tenantHtml, 'badge-done') !== false, "badge-done missing in tenant_portal");
assert(strpos($tenantHtml, 'badge-ongoing') !== false, "badge-ongoing missing in tenant_portal");
assert(strpos($tenantHtml, 'badge-pending') !== false, "badge-pending missing in tenant_portal");
echo "  -> PASS: Verified tenant portal status mapping.\n\n";

echo "=== ALL INTEGRATION TESTS PASSED SUCCESSFULLY! ===\n";
