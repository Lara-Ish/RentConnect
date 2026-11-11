<?php
/**
 * RentConnect - Tenant Management & Automatic Archiving Service
 * Handles tenant move-out status, settlement auditing, and automatic archiving of fully-settled moved-out tenants.
 */

declare(strict_types=1);

class TenantService
{
    /**
     * Audit whether a tenant has fully settled all financial obligations.
     * Conditions for full settlement:
     * 1. No unpaid bills remain (unpaid, overdue, pending_setup, rejected).
     * 2. No outstanding rental balances remain (amount_due > amount_paid).
     * 3. No pending payment verification requests remain (status = 'pending').
     *
     * @param PDO $pdo
     * @param int $tenantId
     * @return array Audit result including is_settled, pending_count, unpaid_count, unpaid_balance, reasons
     */
    public static function checkTenantSettlement(PDO $pdo, int $tenantId): array
    {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_records,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status IN ('unpaid', 'overdue', 'pending_setup', 'rejected') THEN 1 ELSE 0 END) AS unpaid_count,
                SUM(CASE WHEN (COALESCE(amount_due, 0) - COALESCE(amount_paid, 0)) > 0.01 THEN (amount_due - amount_paid) ELSE 0 END) AS total_unpaid_balance,
                SUM(COALESCE(amount_due, 0)) AS total_amount_due,
                SUM(COALESCE(amount_paid, 0)) AS total_amount_paid
            FROM payments 
            WHERE tenant_id = :tid
        ");
        $stmt->execute([':tid' => $tenantId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalRecords  = (int)($res['total_records'] ?? 0);
        $pendingCount  = (int)($res['pending_count'] ?? 0);
        $unpaidCount   = (int)($res['unpaid_count'] ?? 0);
        $unpaidBalance = round((float)($res['total_unpaid_balance'] ?? 0.0), 2);
        $totalDue      = round((float)($res['total_amount_due'] ?? 0.0), 2);
        $totalPaid     = round((float)($res['total_amount_paid'] ?? 0.0), 2);

        $isSettled = ($pendingCount === 0 && $unpaidCount === 0 && $unpaidBalance <= 0.01);

        $reasons = [];
        if ($pendingCount > 0) {
            $reasons[] = "{$pendingCount} payment receipt" . ($pendingCount === 1 ? '' : 's') . " awaiting verification";
        }
        if ($unpaidCount > 0) {
            $reasons[] = "{$unpaidCount} unpaid or overdue bill" . ($unpaidCount === 1 ? '' : 's');
        }
        if ($unpaidBalance > 0.01) {
            $reasons[] = "outstanding balance of ₱" . number_format($unpaidBalance, 2);
        }

        return [
            'is_settled'               => $isSettled,
            'total_records'            => $totalRecords,
            'pending_count'            => $pendingCount,
            'unpaid_count'             => $unpaidCount,
            'unpaid_balance'           => $unpaidBalance,
            'unpaid_balance_formatted' => '₱' . number_format($unpaidBalance, 2),
            'total_amount_due'         => $totalDue,
            'total_amount_paid'        => $totalPaid,
            'reasons'                  => $reasons,
            'reason_text'              => !empty($reasons) ? implode(', ', $reasons) : 'All bills and charges are fully settled'
        ];
    }

    /**
     * Mark a tenant as Moved Out and automatically archive if all obligations are settled.
     * If unpaid balances or pending payments remain, the account and payments stay active.
     *
     * @param PDO $pdo
     * @param int $tenantId
     * @return array Action result
     */
    public static function markMovedOut(PDO $pdo, int $tenantId): array
    {
        $stmt = $pdo->prepare("
            SELECT u.*, un.unit_number 
            FROM users u
            LEFT JOIN units un ON u.unit_id = un.id
            WHERE u.id = :id AND u.role = 'tenant'
            LIMIT 1
        ");
        $stmt->execute([':id' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tenant) {
            return [
                'status'  => 'error',
                'message' => 'Tenant account not found.'
            ];
        }

        if ((int)$tenant['is_archived'] === 1 || (int)$tenant['is_active'] === 0) {
            return [
                'status'  => 'error',
                'message' => 'Tenant account has already been archived.'
            ];
        }

        // 1. Officially mark tenant as Moved Out in the database
        $pdo->prepare("
            UPDATE users 
            SET rental_status = 'moved_out', 
                move_out_date = COALESCE(move_out_date, CURDATE()),
                updated_at = NOW() 
            WHERE id = :id
        ")->execute([':id' => $tenantId]);

        // 2. Audit settlement conditions
        $settlement = self::checkTenantSettlement($pdo, $tenantId);

        // 3. Condition check: BOTH Moved Out AND Fully Settled must be satisfied
        if ($settlement['is_settled']) {
            self::archiveSettledTenant($pdo, $tenantId, $tenant);

            return [
                'status'          => 'success',
                'archived'        => true,
                'tenant_id'       => $tenantId,
                'tenant_name'     => $tenant['name'],
                'unit_id'         => (int)($tenant['unit_id'] ?? 0),
                'unit_number'     => $tenant['unit_number'] ?? 'Unit',
                'rental_status'   => 'moved_out',
                'settlement'      => $settlement,
                'message'         => "Tenant '{$tenant['name']}' has officially moved out and all bills are fully settled. The tenant account and payment history have been automatically transferred to Archived Tenants."
            ];
        } else {
            // Keep in active system and active payment verification
            return [
                'status'          => 'pending_settlement',
                'archived'        => false,
                'tenant_id'       => $tenantId,
                'tenant_name'     => $tenant['name'],
                'unit_id'         => (int)($tenant['unit_id'] ?? 0),
                'unit_number'     => $tenant['unit_number'] ?? 'Unit',
                'rental_status'   => 'moved_out',
                'settlement'      => $settlement,
                'message'         => "Tenant '{$tenant['name']}' has been officially marked as Moved Out. However, the account cannot be archived yet because {$settlement['reason_text']}. The account and payment records will remain active in Payment Verification until all balances are fully settled."
            ];
        }
    }

    /**
     * Archive a fully settled tenant.
     * Safely preserves complete personal info, billing ledger, receipts, and maintenance tickets in MySQL.
     *
     * @param PDO $pdo
     * @param int $tenantId
     * @param array|null $target
     * @return bool
     */
    public static function archiveSettledTenant(PDO $pdo, int $tenantId, ?array $target = null): bool
    {
        $startedTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            if (!$target) {
                $check = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 'tenant' LIMIT 1 FOR UPDATE");
                $check->execute([':id' => $tenantId]);
                $target = $check->fetch(PDO::FETCH_ASSOC);
                if (!$target) {
                    if ($startedTransaction) $pdo->rollBack();
                    return false;
                }
            }

            $unitId = (int)($target['unit_id'] ?? 0);

            // 1. Capture previous rental details for permanent historical audit
            $archivedUnitNum = $target['archived_unit_number'] ?? null;
            $archivedRent = (float)($target['archived_monthly_rent'] ?? 3000.00);
            if ($unitId > 0) {
                $uStmt = $pdo->prepare("SELECT unit_number, monthly_rent FROM units WHERE id = :uid LIMIT 1");
                $uStmt->execute([':uid' => $unitId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow) {
                    $archivedUnitNum = $uRow['unit_number'];
                    $archivedRent = (float)$uRow['monthly_rent'];
                }
            }

            // 2. Capture assigned additional rental spaces for permanent historical audit
            $spacesStmt = $pdo->prepare("
                SELECT space_number, space_name, monthly_fee 
                FROM additional_spaces 
                WHERE assigned_tenant_id = :tid 
                ORDER BY space_number ASC
            ");
            $spacesStmt->execute([':tid' => $tenantId]);
            $spacesRows = $spacesStmt->fetchAll(PDO::FETCH_ASSOC);
            $spacesParts = [];
            foreach ($spacesRows as $sr) {
                $spacesParts[] = "{$sr['space_name']} (₱" . number_format((float)$sr['monthly_fee'], 2) . "/mo)";
            }
            $archivedSpacesText = !empty($spacesParts) ? implode(', ', $spacesParts) : ($target['archived_spaces_text'] ?? null);

            // 3. Release any assigned additional rental spaces
            $pdo->prepare("
                UPDATE additional_spaces 
                SET status = 'available', 
                    assigned_tenant_id = NULL, 
                    assigned_unit_id = NULL, 
                    rented_at = NULL 
                WHERE assigned_tenant_id = :id
            ")->execute([':id' => $tenantId]);

            // 4. Archive Tenant Account (Soft-deletion with permanent record preservation)
            $upd = $pdo->prepare("
                UPDATE users 
                SET is_active = 0,
                    is_archived = 1,
                    rental_status = 'moved_out',
                    deleted_at = COALESCE(deleted_at, NOW()),
                    move_out_date = COALESCE(move_out_date, CURDATE()),
                    archived_unit_number = :a_unit_num,
                    archived_monthly_rent = :a_rent,
                    archived_spaces_text = :a_spaces,
                    unit_id = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $upd->execute([
                ':a_unit_num' => $archivedUnitNum,
                ':a_rent'     => $archivedRent,
                ':a_spaces'   => $archivedSpacesText,
                ':id'         => $tenantId
            ]);

            // 5. Release apartment_settings active_tenant_id if set
            $pdo->prepare("UPDATE apartment_settings SET active_tenant_id = NULL WHERE active_tenant_id = :id")->execute([':id' => $tenantId]);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return true;
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[TenantService] Failed to archive tenant: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check all active tenants marked 'moved_out' and automatically archive any whose bills are fully settled.
     * Called upon payment verification, ledger updates, or dashboard sync.
     *
     * @param PDO $pdo
     * @return int Number of tenants automatically archived
     */
    public static function syncMovedOutSettlements(PDO $pdo): int
    {
        $stmt = $pdo->query("
            SELECT * FROM users 
            WHERE role = 'tenant' 
              AND is_active = 1 
              AND (is_archived = 0 OR is_archived IS NULL)
              AND rental_status = 'moved_out'
        ");
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $archivedCount = 0;

        foreach ($candidates as $cand) {
            $settlement = self::checkTenantSettlement($pdo, (int)$cand['id']);
            if ($settlement['is_settled']) {
                if (self::archiveSettledTenant($pdo, (int)$cand['id'], $cand)) {
                    $archivedCount++;
                }
            }
        }

        return $archivedCount;
    }
}
