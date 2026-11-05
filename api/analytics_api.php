<?php
/**
 * RentConnect - Business Intelligence Analytics API
 * Executes Descriptive & Diagnostic Analytics for the 3-Unit Apartment Property
 * Tracks verified receipts & manual payment approvals in ₱
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();

    // 1. High-Level Portfolio KPIs across the 3 Units
    // Considers approved payments as verified revenue
    $kpiStmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT u.id) AS total_units,
            (SELECT COUNT(*) FROM users WHERE role = 'tenant' AND is_active = 1) AS total_active_tenants,
            COALESCE(SUM(CASE WHEN p.status != 'pending_setup' THEN p.amount_due ELSE 0 END), 0) AS total_receivables_billed,
            COALESCE(SUM(CASE WHEN p.status = 'approved' THEN p.amount_paid ELSE 0 END), 0) AS total_revenue_collected,
            COALESCE(SUM(CASE WHEN p.status NOT IN ('approved', 'pending_setup') AND p.amount_due IS NOT NULL THEN (p.amount_due - p.amount_paid) ELSE 0 END), 0) AS total_outstanding_balance,
            CASE 
                WHEN SUM(CASE WHEN p.status != 'pending_setup' THEN p.amount_due ELSE 0 END) > 0 
                THEN ROUND((SUM(CASE WHEN p.status = 'approved' THEN p.amount_paid ELSE 0 END) / SUM(CASE WHEN p.status != 'pending_setup' THEN p.amount_due ELSE 0 END)) * 100, 2) 
                ELSE 0.00 
            END AS overall_collection_efficiency
        FROM units u
        LEFT JOIN payments p ON p.unit_id = u.id
    ");
    $finKpis = $kpiStmt->fetch();

    // 2. Maintenance Expense KPIs
    $maintKpiStmt = $pdo->query("
        SELECT 
            COALESCE(SUM(actual_cost), 0) AS total_maintenance_expenditure,
            COUNT(id) AS total_tickets_logged,
            SUM(CASE WHEN status IN ('open', 'in_progress') THEN 1 ELSE 0 END) AS active_tickets,
            SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS resolved_tickets
        FROM maintenance_tickets
    ");
    $maintKpis = $maintKpiStmt->fetch();

    // 3. Monthly Collection Efficiency Timeline across 3 Units
    $monthlyStmt = $pdo->query("
        SELECT 
            SUBSTR(billing_cycle, 1, 7) AS month_key,
            ROUND(SUM(amount_due), 2) AS billed_rent,
            ROUND(SUM(CASE WHEN status = 'approved' THEN amount_paid ELSE 0 END), 2) AS collected_rent,
            ROUND(SUM(CASE WHEN status != 'approved' THEN (amount_due - amount_paid) ELSE 0 END), 2) AS outstanding_balance,
            CASE 
                WHEN SUM(amount_due) > 0 
                THEN ROUND((SUM(CASE WHEN status = 'approved' THEN amount_paid ELSE 0 END) / SUM(amount_due)) * 100, 2) 
                ELSE 0.00 
            END AS collection_efficiency_percentage
        FROM payments
        WHERE billing_cycle IS NOT NULL AND status != 'pending_setup'
        GROUP BY SUBSTR(billing_cycle, 1, 7)
        ORDER BY month_key ASC
    ");
    $monthlyCollections = $monthlyStmt->fetchAll();

    foreach ($monthlyCollections as &$row) {
        $dateObj = DateTime::createFromFormat('Y-m', $row['month_key']);
        $row['month_label'] = $dateObj ? $dateObj->format('M Y') : $row['month_key'];
        $row['billed_rent'] = (float)$row['billed_rent'];
        $row['collected_rent'] = (float)$row['collected_rent'];
        $row['outstanding_balance'] = (float)$row['outstanding_balance'];
        $row['collection_efficiency_percentage'] = (float)$row['collection_efficiency_percentage'];
    }
    unset($row);

    // 4. Maintenance Expenses per Unit (Unit 101, 102, 103)
    $unitMaintStmt = $pdo->query("
        SELECT 
            u.id AS unit_id,
            u.unit_number,
            u.monthly_rent,
            COUNT(mt.id) AS ticket_count,
            COALESCE(ROUND(SUM(mt.actual_cost), 2), 0.00) AS total_cost,
            CASE 
                WHEN COUNT(mt.id) > 0 THEN ROUND(SUM(mt.actual_cost) / COUNT(mt.id), 2) 
                ELSE 0.00 
            END AS avg_cost_per_ticket
        FROM units u
        LEFT JOIN maintenance_tickets mt ON mt.unit_id = u.id
        GROUP BY u.id, u.unit_number, u.monthly_rent
        ORDER BY u.unit_number ASC
    ");
    $unitMaintenance = $unitMaintStmt->fetchAll();

    foreach ($unitMaintenance as &$um) {
        $um['total_cost'] = (float)$um['total_cost'];
        $um['ticket_count'] = (int)$um['ticket_count'];
        $um['avg_cost_per_ticket'] = (float)$um['avg_cost_per_ticket'];
    }
    unset($um);

    // 5. Repair Category Categorization & Share
    $catStmt = $pdo->query("
        SELECT 
            category,
            COUNT(id) AS ticket_count,
            ROUND(SUM(actual_cost), 2) AS total_cost,
            CASE 
                WHEN COUNT(id) > 0 THEN ROUND(SUM(actual_cost) / COUNT(id), 2) 
                ELSE 0.00 
            END AS avg_cost
        FROM maintenance_tickets
        GROUP BY category
        ORDER BY total_cost DESC
    ");
    $categoriesRaw = $catStmt->fetchAll();

    $totalMaintAll = (float)$maintKpis['total_maintenance_expenditure'];
    $categoryAnalytics = [];
    foreach ($categoriesRaw as $c) {
        $cost = (float)$c['total_cost'];
        $share = $totalMaintAll > 0 ? round(($cost / $totalMaintAll) * 100, 1) : 0;
        $categoryAnalytics[] = [
            'category' => $c['category'],
            'ticket_count' => (int)$c['ticket_count'],
            'total_cost' => $cost,
            'avg_cost' => (float)$c['avg_cost'],
            'cost_share_percentage' => $share
        ];
    }

    // 6. Diagnostic Operational Alerts
    $diagnosticAlerts = [];
    if (!empty($categoryAnalytics)) {
        $topCat = $categoryAnalytics[0];
        $topCatFormattedCost = number_format((float)$topCat['total_cost'], 2);
        $diagnosticAlerts[] = [
            'type' => 'category_driver',
            'severity' => 'warning',
            'title' => "Primary Expense Category: {$topCat['category']}",
            'message' => "{$topCat['category']} represents {$topCat['cost_share_percentage']}% of all repair expenditures across the 3 units (₱{$topCatFormattedCost})."
        ];
    }

    $overdueStmt = $pdo->query("
        SELECT p.billing_cycle, p.due_date, p.amount_due, p.amount_paid, p.status, (p.amount_due - p.amount_paid) AS balance,
               u.unit_number, usr.name AS tenant_name
        FROM payments p
        JOIN units u ON p.unit_id = u.id
        JOIN users usr ON p.tenant_id = usr.id
        WHERE p.status IN ('rejected', 'overdue') 
           OR (p.status = 'pending' AND p.due_date < CURDATE() AND p.amount_paid < p.amount_due)
        ORDER BY p.billing_cycle DESC
    ");
    $overdues = $overdueStmt->fetchAll();

    foreach ($overdues as $ov) {
        $dateObj = DateTime::createFromFormat('Y-m-d', $ov['billing_cycle']);
        $cycle = $dateObj ? $dateObj->format('M Y') : $ov['billing_cycle'];
        $formattedBalance = number_format((float)$ov['balance'], 2);
        $statusLabel = strtoupper($ov['status']);
        $diagnosticAlerts[] = [
            'type' => 'arrears',
            'severity' => 'error',
            'title' => "{$ov['unit_number']} Outstanding Balance: {$cycle} ({$statusLabel})",
            'message' => "{$ov['tenant_name']} has an unverified balance of ₱{$formattedBalance} for {$cycle} (Due: {$ov['due_date']})."
        ];
    }

    // Pending Approvals Count for Manual Verification
    $pendingStmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND receipt_filename IS NOT NULL");
    $pendingApprovalsCount = (int)$pendingStmt->fetchColumn();

    $response = [
        'status' => 'success',
        'generated_at' => date('c'),
        'kpis' => [
            'units' => [
                'total_units' => (int)$finKpis['total_units'],
                'total_active_tenants' => (int)$finKpis['total_active_tenants']
            ],
            'financials' => [
                'total_receivables_billed' => (float)$finKpis['total_receivables_billed'],
                'total_revenue_collected' => (float)$finKpis['total_revenue_collected'],
                'total_outstanding_balance' => (float)$finKpis['total_outstanding_balance'],
                'overall_collection_efficiency_pct' => (float)$finKpis['overall_collection_efficiency']
            ],
            'maintenance' => [
                'total_expenditure' => $totalMaintAll,
                'total_tickets_logged' => (int)$maintKpis['total_tickets_logged'],
                'active_tickets' => (int)$maintKpis['active_tickets'],
                'resolved_tickets' => (int)$maintKpis['resolved_tickets']
            ],
            'manual_verification' => [
                'pending_receipts_count' => $pendingApprovalsCount
            ]
        ],
        'charts' => [
            'monthly_collection_efficiency' => $monthlyCollections,
            'maintenance_by_unit' => $unitMaintenance,
            'maintenance_by_category' => $categoryAnalytics
        ],
        'diagnostic_alerts' => $diagnosticAlerts
    ];

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Analytics query error: ' . $e->getMessage()
    ]);
}
