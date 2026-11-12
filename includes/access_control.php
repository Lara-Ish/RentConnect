<?php
/**
 * RentConnect / TaraRent - Centralized Access Control & Security Guards
 * Provides owner password verification for sensitive tenant deletions and
 * real-time MySQL-backed read-only enforcement for unverified tenant accounts.
 */

declare(strict_types=1);

class AccessControl
{
    public const UNVERIFIED_FEATURE_BLOCK_MESSAGE =
        'This feature is currently unavailable. Please complete your account verification to access this functionality.';

    /**
     * Evaluate whether a user database row is verified for transactional actions.
     * Landlords/owners are never restricted by tenant verification status.
     */
    public static function isUserVerifiedRow(array $userRow): bool
    {
        if (strtolower((string)($userRow['role'] ?? '')) === 'landlord') {
            return true;
        }

        $verificationStatus = strtolower(trim((string)($userRow['verification_status'] ?? 'unverified')));
        $isVerifiedFlag = (int)($userRow['is_verified'] ?? 0);

        if ($verificationStatus === 'verified') {
            return true;
        }

        return ($isVerifiedFlag === 1) && ($verificationStatus !== 'rejected');
    }

    /**
     * Fetch the tenant's live verification state directly from MySQL so
     * permission upgrades/revocations take effect immediately without re-login.
     */
    public static function getUserVerificationState(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [
                'found' => false,
                'is_active' => 0,
                'is_archived' => 0,
                'role' => null,
                'verification_status' => 'unverified',
                'is_verified' => 0,
                'verified' => false,
            ];
        }

        $stmt = $pdo->prepare("
            SELECT id, role, is_active, is_archived, verification_status, is_verified
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'found' => false,
                'is_active' => 0,
                'is_archived' => 0,
                'role' => null,
                'verification_status' => 'unverified',
                'is_verified' => 0,
                'verified' => false,
            ];
        }

        $isActive = (int)($row['is_active'] ?? 0);
        $isArchived = (int)($row['is_archived'] ?? 0);
        $verified = ($isActive === 1 && $isArchived === 0) && self::isUserVerifiedRow($row);

        return [
            'found' => true,
            'is_active' => $isActive,
            'is_archived' => $isArchived,
            'role' => strtolower((string)($row['role'] ?? 'tenant')),
            'verification_status' => (string)($row['verification_status'] ?? 'unverified'),
            'is_verified' => (int)($row['is_verified'] ?? 0),
            'verified' => $verified,
        ];
    }

    /**
     * Convenience helper for checking if a user ID currently has verified access.
     */
    public static function isTenantVerified(PDO $pdo, int $userId): bool
    {
        return self::getUserVerificationState($pdo, $userId)['verified'];
    }

    /**
     * Enforce live verified-account check on JSON endpoints for tenant mutations.
     * Landlords bypass this check; unauthenticated or inactive users receive 401;
     * unverified/pending/rejected tenants receive 403.
     */
    public static function requireVerifiedTenantJson(PDO $pdo, ?array $currentUser = null): array
    {
        $sessionUser = $currentUser ?? Auth::user();
        $userId = (int)($sessionUser['id'] ?? 0);

        if ($userId <= 0 || !Auth::check()) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Unauthorized: Please sign in to perform this action.',
            ]);
            exit;
        }

        $state = self::getUserVerificationState($pdo, $userId);
        if (!$state['found'] || $state['is_active'] !== 1 || $state['is_archived'] === 1) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Unauthorized: This account is inactive or no longer accessible.',
            ]);
            exit;
        }

        if ($state['role'] === 'landlord') {
            return $state;
        }

        if (!$state['verified']) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'code' => 'ACCOUNT_NOT_VERIFIED',
                'verification_status' => $state['verification_status'],
                'is_verified' => $state['is_verified'],
                'message' => self::UNVERIFIED_FEATURE_BLOCK_MESSAGE,
            ]);
            exit;
        }

        return $state;
    }

    /**
     * Validate the currently authenticated owner/landlord's account password
     * using PHP password_verify() against MySQL. Never logs or stores plaintext.
     */
    public static function verifyOwnerPassword(PDO $pdo, string $enteredPassword): array
    {
        if (!Auth::isLandlord()) {
            return [
                'valid' => false,
                'status_code' => 403,
                'message' => 'Unauthorized: Only the property owner can delete tenant accounts.',
            ];
        }

        $ownerUser = Auth::user();
        $ownerId = (int)($ownerUser['id'] ?? 0);
        if ($ownerId <= 0) {
            return [
                'valid' => false,
                'status_code' => 401,
                'message' => 'Owner authentication session is missing or expired. Please sign in again.',
            ];
        }

        if ($enteredPassword === '') {
            return [
                'valid' => false,
                'status_code' => 422,
                'message' => 'Please enter your current owner password to confirm this deletion.',
            ];
        }

        $stmt = $pdo->prepare("
            SELECT id, username, name, role, password_hash, is_active
            FROM users
            WHERE id = :id AND role = 'landlord' AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':id' => $ownerId]);
        $ownerRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ownerRow || empty($ownerRow['password_hash'])) {
            return [
                'valid' => false,
                'status_code' => 403,
                'message' => 'Unable to verify active owner account credentials.',
            ];
        }

        if (!password_verify($enteredPassword, (string)$ownerRow['password_hash'])) {
            return [
                'valid' => false,
                'status_code' => 403,
                'message' => 'Incorrect owner password. Tenant account deletion was denied.',
            ];
        }

        return [
            'valid' => true,
            'status_code' => 200,
            'owner_id' => (int)$ownerRow['id'],
        ];
    }
}
