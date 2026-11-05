-- ============================================================================
-- RentConnect: Complete Tenant & Records Database Wipe Script
-- Wipes all records from payments, maintenance_tickets, and tenant accounts from users
-- Strictly PRESERVES the Landlord Admin account (role = 'landlord')
-- ============================================================================

USE `rentconnect`;

-- Temporarily disable foreign key constraints to allow clean truncation
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Wipe all payment records and reset auto-increment
TRUNCATE TABLE `payments`;

-- 2. Wipe all maintenance ticket records and reset auto-increment
TRUNCATE TABLE `maintenance_tickets`;

-- 3. Wipe all tenant records while strictly preserving the Landlord Admin account
DELETE FROM `users` WHERE `role` != 'landlord';

-- 4. Reset active tenant reference in apartment settings (if set)
UPDATE `apartment_settings` SET `active_tenant_id` = NULL WHERE `id` = 1;

-- 5. Wipe demo lease records (optional, ensures clean slate for unit folders)
TRUNCATE TABLE `leases`;

-- 6. Ensure the 3 units strictly have their hardcoded layout and pricing
UPDATE `units` SET `unit_number` = 'Unit 1', `bedrooms` = 1, `bathrooms` = 1.0, `floor_level` = 1, `monthly_rent` = 3000.00 WHERE `id` = 1;
UPDATE `units` SET `unit_number` = 'Unit 2', `bedrooms` = 2, `bathrooms` = 1.0, `floor_level` = 1, `monthly_rent` = 3000.00 WHERE `id` = 2;
UPDATE `units` SET `unit_number` = 'Unit 3', `bedrooms` = 3, `bathrooms` = 1.0, `floor_level` = 2, `monthly_rent` = 3000.00 WHERE `id` = 3;

-- Re-enable foreign key constraints
SET FOREIGN_KEY_CHECKS = 1;

-- Verification Queries:
-- SELECT 'Remaining Users (Landlord Only):' AS info, id, username, role FROM `users`;
-- SELECT 'Remaining Payments:' AS info, COUNT(*) AS count FROM `payments`;
-- SELECT 'Remaining Tickets:' AS info, COUNT(*) AS count FROM `maintenance_tickets`;
