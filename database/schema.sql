-- ============================================================================
-- RentConnect: Apartment Management, Maintenance Ticketing, and Payment Tracker
-- SQL Database Architecture & Seed Data (MySQL / MariaDB Compatible)
-- Architecture: 1 Apartment with Exactly 3 Units & Unlimited Tenants per Unit
-- Currency: ₱ (Standard Symbol)
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `rentconnect` 
DEFAULT CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `rentconnect`;

-- Disable foreign key checks for clean re-creation
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `maintenance_tickets`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `units`;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- 1. UNITS TABLE (Exactly 3 Units, Unlimited Tenant Capacity)
-- ----------------------------------------------------------------------------
CREATE TABLE `units` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `unit_number` VARCHAR(20) NOT NULL UNIQUE,
    `floor_level` INT NOT NULL DEFAULT 1,
    `bedrooms` INT NOT NULL DEFAULT 2,
    `bathrooms` DECIMAL(3,1) NOT NULL DEFAULT 1.0,
    `monthly_rent` DECIMAL(10,2) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. USERS TABLE
-- Stores Landlord (Lead Dimaculangan) and Active Tenants assigned to units.
-- No public registration. Unlimited tenants can be assigned to a single unit.
-- ----------------------------------------------------------------------------
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `unit_id` INT UNSIGNED NULL COMMENT 'Assigned unit for tenants; NULL for landlord',
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('landlord', 'tenant') NOT NULL DEFAULT 'tenant',
    `verification_status` ENUM('unverified', 'pending', 'pending_liveness', 'verified', 'rejected') NOT NULL DEFAULT 'unverified',
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `id_type` VARCHAR(50) NULL,
    `id_photo_filename` VARCHAR(255) NULL,
    `live_photo_filename` VARCHAR(255) NULL,
    `id_submitted_at` DATETIME NULL,
    `id_verified_at` DATETIME NULL,
    `verification_notes` TEXT NULL,
    `phone` VARCHAR(30) NULL,
    `birthday` DATE NULL,
    `emergency_contact` VARCHAR(150) NULL,
    `emergency_contact_name` VARCHAR(100) NULL,
    `emergency_contact_relationship` VARCHAR(50) NULL,
    `emergency_contact_phone` VARCHAR(30) NULL,
    `move_in_date` DATE NULL,
    `total_occupants` INT NOT NULL DEFAULT 1,
    `adults_count` INT NOT NULL DEFAULT 1,
    `kids_count` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `active_tenant_unit_id` INT UNSIGNED GENERATED ALWAYS AS (IF(role = 'tenant' AND is_active = 1, unit_id, NULL)) STORED,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_users_unit` 
        FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY `uq_active_tenant_unit` (`active_tenant_unit_id`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_username` (`username`),
    INDEX `idx_users_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. PAYMENTS TABLE
-- Tracks monthly rent billing cycles and payment receipts in ₱.
-- Supports manual landlord verification workflow (pending, approved, rejected, overdue).
-- ----------------------------------------------------------------------------
CREATE TABLE `payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `billing_cycle` DATE NOT NULL COMMENT 'First day of billing month e.g. 2026-10-01',
    `due_date` DATE NOT NULL,
    `amount_due` DECIMAL(10,2) NOT NULL,
    `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_date` DATE NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'overdue') NOT NULL DEFAULT 'pending',
    `payment_method` VARCHAR(50) DEFAULT 'GCash / Bank Transfer',
    `receipt_filename` VARCHAR(255) NULL,
    `verified_by_landlord` TINYINT(1) NOT NULL DEFAULT 0,
    `verification_notes` TEXT NULL,
    `verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_payments_tenant` 
        FOREIGN KEY (`tenant_id`) REFERENCES `users` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_payments_unit` 
        FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_payments_cycle` (`billing_cycle`),
    INDEX `idx_payments_status` (`status`),
    INDEX `idx_payments_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. MAINTENANCE TICKETS TABLE
-- Tracks repair work orders categorized per unit with actual repair costs in ₱.
-- ----------------------------------------------------------------------------
CREATE TABLE `maintenance_tickets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `description` TEXT NOT NULL,
    `photo_path` VARCHAR(1000) NULL,
    `category` ENUM('Plumbing', 'Electrical', 'HVAC', 'Appliance', 'Structural', 'General') NOT NULL,
    `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    `status` ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    `actual_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `resolution_notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME NULL,
    CONSTRAINT `fk_tickets_tenant` 
        FOREIGN KEY (`tenant_id`) REFERENCES `users` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_tickets_unit` 
        FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_tickets_category` (`category`),
    INDEX `idx_tickets_unit` (`unit_id`),
    INDEX `idx_tickets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SEED DATA: Exactly 3 Units, Exactly 1 Landlord, and Exactly 2 Active Tenants
-- ============================================================================

-- 1. Exactly 3 Units (Strict layout & pricing: Unit 1 @ ₱6k, Unit 2 @ ₱8k, Unit 3 @ ₱8k)
INSERT INTO `units` (`id`, `unit_number`, `floor_level`, `bedrooms`, `bathrooms`, `monthly_rent`) VALUES
(1, 'Unit 1', 1, 1, 1.0, 6000.00),
(2, 'Unit 2', 1, 2, 1.0, 8000.00),
(3, 'Unit 3', 2, 3, 1.0, 8000.00);

-- 2. Landlord (Lead Dimaculangan) & Exactly Two Active Tenants (tenant1, tenant2)
-- admin: admin123 ($2y$10$L1oJw/e9iaOxHBBWA6p0G.EEPbgJ8jKQxXLEnjzxwY0DSzno7igyO)
-- tenant1: 111111  ($2y$10$shksNt6tczNPIHdM73bUT.v5zFTIUGWVmIYcoPDG/b1Ft5YDs3NLy)
-- tenant2: 222222  ($2y$10$81lsNOUii1VyCUvd/TV1X.qdoJRMY6NeVoMDMnVhnsJ7sJq9qURG6)
INSERT INTO `users` (`id`, `unit_id`, `username`, `name`, `email`, `password_hash`, `role`, `phone`, `birthday`, `emergency_contact`, `move_in_date`) VALUES
(1, NULL, 'admin', 'Lead Dimaculangan', 'lead.dimaculangan@rentconnect.ph', '$2y$10$L1oJw/e9iaOxHBBWA6p0G.EEPbgJ8jKQxXLEnjzxwY0DSzno7igyO', 'landlord', '+63 917 123 4567', '1985-11-20', 'Property Management Office', '2024-01-01'),
(2, 1, 'tenant1', 'Juan Dela Cruz', 'juan.delacruz@rentconnect.ph', '$2y$10$shksNt6tczNPIHdM73bUT.v5zFTIUGWVmIYcoPDG/b1Ft5YDs3NLy', 'tenant', '+63 918 234 5678', '1995-04-12', 'Rosa Dela Cruz (+63 919 333 4444)', '2025-06-01'),
(3, 2, 'tenant2', 'Maria Santos', 'maria.santos@rentconnect.ph', '$2y$10$81lsNOUii1VyCUvd/TV1X.qdoJRMY6NeVoMDMnVhnsJ7sJq9qURG6', 'tenant', '+63 920 345 6789', '1998-08-23', 'Carlos Santos (+63 921 555 6666)', '2025-09-15');

-- 3. Historical Rent Payments for tenant1 and tenant2 (May 2026 to October 2026) in ₱
-- Demonstrates manual landlord verification workflow with pending, approved, and overdue records
INSERT INTO `payments` (`tenant_id`, `unit_id`, `billing_cycle`, `due_date`, `amount_due`, `amount_paid`, `payment_date`, `status`, `receipt_filename`, `verified_by_landlord`, `verification_notes`, `verified_at`) VALUES
-- May 2026 (Approved)
(2, 1, '2026-05-01', '2026-05-05', 15000.00, 15000.00, '2026-05-03', 'approved', 'receipt_may_u101.pdf', 1, 'Verified with bank statement', '2026-05-04 10:30:00'),
(3, 2, '2026-05-01', '2026-05-05', 18000.00, 18000.00, '2026-05-04', 'approved', 'receipt_may_u102.pdf', 1, 'GCash reference matched', '2026-05-05 09:15:00'),

-- June 2026 (Approved)
(2, 1, '2026-06-01', '2026-06-05', 15000.00, 15000.00, '2026-06-02', 'approved', 'receipt_jun_u101.pdf', 1, 'Verified deposit slip', '2026-06-03 14:00:00'),
(3, 2, '2026-06-01', '2026-06-05', 18000.00, 18000.00, '2026-06-05', 'approved', 'receipt_jun_u102.pdf', 1, 'Bank transfer cleared', '2026-06-06 11:20:00'),

-- July 2026 (Approved)
(2, 1, '2026-07-01', '2026-07-05', 15000.00, 15000.00, '2026-07-04', 'approved', 'receipt_jul_u101.pdf', 1, 'Verified online payment', '2026-07-05 16:45:00'),
(3, 2, '2026-07-01', '2026-07-05', 18000.00, 18000.00, '2026-07-03', 'approved', 'receipt_jul_u102.pdf', 1, 'Verified with transaction record', '2026-07-04 13:10:00'),

-- August 2026 (Unit 101 approved, Unit 102 partial/rejected)
(2, 1, '2026-08-01', '2026-08-05', 15000.00, 15000.00, '2026-08-04', 'approved', 'receipt_aug_u101.pdf', 1, 'Verified full payment', '2026-08-05 10:00:00'),
(3, 2, '2026-08-01', '2026-08-05', 18000.00, 12000.00, '2026-08-08', 'rejected', 'receipt_aug_u102.pdf', 0, 'Partial payment only. Remaining balance of ₱6,000 required.', '2026-08-09 11:30:00'),

-- September 2026 (Approved)
(2, 1, '2026-09-01', '2026-09-05', 15000.00, 15000.00, '2026-09-02', 'approved', 'receipt_sep_u101.pdf', 1, 'Verified payment slip', '2026-09-03 09:40:00'),
(3, 2, '2026-09-01', '2026-09-05', 18000.00, 18000.00, '2026-09-04', 'approved', 'receipt_sep_u102.pdf', 1, 'Verified electronic transfer', '2026-09-05 15:00:00'),

-- October 2026 (Unit 101 PENDING review for landlord manual verification; Unit 102 overdue)
(2, 1, '2026-10-01', '2026-10-05', 15000.00, 15000.00, '2026-10-03', 'pending', 'receipt_oct_u101.pdf', 0, NULL, NULL),
(3, 2, '2026-10-01', '2026-10-05', 18000.00, 0.00, NULL, 'overdue', NULL, 0, 'No payment submitted for October cycle', NULL);

-- ----------------------------------------------------------------------------
-- TRIGGERS: ENFORCE STRICT 1 ACTIVE TENANT PER APARTMENT UNIT RESTRICTION
-- ----------------------------------------------------------------------------
DELIMITER //

CREATE TRIGGER `trg_users_one_active_tenant_insert`
BEFORE INSERT ON `users`
FOR EACH ROW
BEGIN
    IF NEW.role = 'tenant' AND NEW.is_active = 1 AND NEW.unit_id IS NOT NULL THEN
        IF (SELECT COUNT(*) FROM `users` WHERE `unit_id` = NEW.unit_id AND `role` = 'tenant' AND `is_active` = 1) > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.';
        END IF;
    END IF;
END//

CREATE TRIGGER `trg_users_one_active_tenant_update`
BEFORE UPDATE ON `users`
FOR EACH ROW
BEGIN
    IF NEW.role = 'tenant' AND NEW.is_active = 1 AND NEW.unit_id IS NOT NULL THEN
        IF (SELECT COUNT(*) FROM `users` WHERE `unit_id` = NEW.unit_id AND `role` = 'tenant' AND `is_active` = 1 AND `id` != OLD.id) > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.';
        END IF;
    END IF;
END//

DELIMITER ;

-- 4. Maintenance Tickets Across the Units (Incurred costs in ₱)
INSERT INTO `maintenance_tickets` (`tenant_id`, `unit_id`, `title`, `description`, `category`, `priority`, `status`, `actual_cost`, `created_at`, `resolved_at`) VALUES
(2, 1, 'Kitchen Faucet Leak', 'Constant dripping beneath the single-handle kitchen sink pipe.', 'Plumbing', 'medium', 'resolved', 1850.00, '2026-05-14 10:00:00', '2026-05-15 15:30:00'),
(3, 2, 'Aircon Condenser Blower Noise', 'Split-type AC outdoor unit making heavy vibrating sound.', 'HVAC', 'urgent', 'resolved', 4800.00, '2026-06-20 14:00:00', '2026-06-22 16:00:00'),
(2, 1, 'Range Hood Filter Exhaust Jam', 'Grease filter fan motor humming but not spinning.', 'Appliance', 'low', 'resolved', 1200.00, '2026-07-18 09:30:00', '2026-07-19 11:00:00'),
(3, 2, 'Main Bathroom Light Switch Loose', 'Rocker switch plate cracked and sparking faintly.', 'Electrical', 'high', 'resolved', 1600.00, '2026-08-11 11:15:00', '2026-08-12 14:20:00'),
(2, 1, 'Balcony Sliding Screen Mesh Tear', 'Screen mesh detached from upper aluminum frame.', 'Structural', 'low', 'resolved', 950.00, '2026-09-15 16:00:00', '2026-09-16 12:00:00'),
(3, 2, 'Water Heater Breaker Trip', 'Shower heater trips breaker after 5 minutes of usage.', 'Electrical', 'high', 'in_progress', 2200.00, '2026-10-08 13:00:00', NULL);
