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
    `email` VARCHAR(150) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('landlord', 'tenant') NOT NULL DEFAULT 'tenant',
    `rental_status` ENUM('active', 'moved_out') NOT NULL DEFAULT 'active',
    `verification_status` ENUM('unverified', 'pending', 'pending_liveness', 'verified', 'rejected') NOT NULL DEFAULT 'unverified',
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `id_type` VARCHAR(50) NULL,
    `id_photo_filename` VARCHAR(255) NULL,
    `live_photo_filename` VARCHAR(255) NULL,
    `profile_picture` VARCHAR(255) NULL,
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
    `move_out_date` DATE NULL,
    `total_occupants` INT NOT NULL DEFAULT 1,
    `adults_count` INT NOT NULL DEFAULT 1,
    `kids_count` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `deleted_at` DATETIME NULL,
    `archived_unit_number` VARCHAR(50) NULL,
    `archived_monthly_rent` DECIMAL(10,2) NULL,
    `archived_spaces_text` VARCHAR(255) NULL,
    `active_tenant_unit_id` INT UNSIGNED GENERATED ALWAYS AS (IF(role = 'tenant' AND is_active = 1, unit_id, NULL)) STORED,
    `active_tenant_email` VARCHAR(150) GENERATED ALWAYS AS (IF(is_active = 1 AND (is_archived = 0 OR is_archived IS NULL), email, NULL)) STORED,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_users_unit` 
        FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY `uq_active_tenant_unit` (`active_tenant_unit_id`),
    UNIQUE KEY `uq_active_tenant_email` (`active_tenant_email`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_username` (`username`),
    INDEX `idx_users_unit` (`unit_id`),
    INDEX `idx_users_email` (`email`)
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
    `payment_type` VARCHAR(50) NOT NULL DEFAULT 'Rent',
    `billing_cycle` DATE NULL DEFAULT NULL COMMENT 'First day of billing month e.g. 2026-10-01',
    `due_date` DATE NULL DEFAULT NULL,
    `base_rent_amount` DECIMAL(10,2) NOT NULL DEFAULT 3000.00,
    `additional_space_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `additional_spaces_breakdown` VARCHAR(255) NULL DEFAULT NULL,
    `amount_due` DECIMAL(10,2) NULL DEFAULT NULL,
    `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_date` DATE NULL,
    `status` ENUM('pending_setup', 'unpaid', 'pending', 'approved', 'rejected', 'overdue') NOT NULL DEFAULT 'pending_setup',
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

-- ----------------------------------------------------------------------------
-- 5. ADDITIONAL RENTAL SPACES TABLE (Parking / Extra Lots)
-- Exactly 2 available rentable spaces at ₱1,500.00/month each.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `additional_spaces` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `space_number` INT UNSIGNED NOT NULL UNIQUE,
    `space_name` VARCHAR(50) NOT NULL,
    `monthly_fee` DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
    `status` ENUM('available', 'occupied') NOT NULL DEFAULT 'available',
    `assigned_tenant_id` INT UNSIGNED NULL,
    `assigned_unit_id` INT UNSIGNED NULL,
    `rented_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_additional_spaces_tenant` 
        FOREIGN KEY (`assigned_tenant_id`) REFERENCES `users` (`id`) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_additional_spaces_unit` 
        FOREIGN KEY (`assigned_unit_id`) REFERENCES `units` (`id`) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_spaces_status` (`status`),
    INDEX `idx_spaces_tenant` (`assigned_tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the 2 Additional Spaces (Space 1 and Space 2)
INSERT INTO `additional_spaces` (`id`, `space_number`, `space_name`, `monthly_fee`, `status`) VALUES
(1, 1, 'Space 1', 1500.00, 'available'),
(2, 2, 'Space 2', 1500.00, 'available')
ON DUPLICATE KEY UPDATE `space_name` = VALUES(`space_name`), `monthly_fee` = VALUES(`monthly_fee`);

-- ============================================================================
-- SEED DATA: Exactly 3 Units, Exactly 1 Landlord, and Exactly 2 Active Tenants
-- ============================================================================

-- 1. Exactly 3 Units (Strict layout & pricing: ₱3,000.00 per month)
INSERT INTO `units` (`id`, `unit_number`, `floor_level`, `bedrooms`, `bathrooms`, `monthly_rent`) VALUES
(1, 'Unit 1', 1, 1, 1.0, 3000.00),
(2, 'Unit 2', 1, 2, 1.0, 3000.00),
(3, 'Unit 3', 2, 3, 1.0, 3000.00);

-- 2. Landlord (Lead Dimaculangan)
-- admin: admin123 ($2y$10$L1oJw/e9iaOxHBBWA6p0G.EEPbgJ8jKQxXLEnjzxwY0DSzno7igyO)
INSERT INTO `users` (`id`, `unit_id`, `username`, `name`, `email`, `password_hash`, `role`, `phone`, `birthday`, `emergency_contact`, `move_in_date`) VALUES
(1, NULL, 'admin', 'Lead Dimaculangan', 'rentconnect2026@gmail.com', '$2y$10$L1oJw/e9iaOxHBBWA6p0G.EEPbgJ8jKQxXLEnjzxwY0DSzno7igyO', 'landlord', '+63 917 123 4567', '1985-11-20', 'Property Management Office', '2024-01-01');

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

-- ----------------------------------------------------------------------------
-- 5. PASSWORD RESETS TABLE
-- Secure token- and 6-digit verification code-based password recovery requests.
-- Stores cryptographically secure SHA-256 hashed codes with 5-minute expiry and rate-limiting.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_token_hash` (`token_hash`),
    INDEX `idx_user_resets` (`user_id`, `expires_at`),
    CONSTRAINT `fk_password_resets_user` 
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. EMAIL LOGS TABLE
-- Audit log of all outgoing PHPMailer deliveries & scheduled notification tracking.
-- Never logs passwords, tokens, verification codes, or sensitive credentials.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recipient_user_id` INT UNSIGNED NULL,
    `recipient_email` VARCHAR(191) NOT NULL,
    `recipient_name` VARCHAR(100) NULL,
    `subject` VARCHAR(255) NOT NULL,
    `template_type` VARCHAR(50) NOT NULL,
    `reference_id` INT UNSIGNED NULL,
    `reference_type` VARCHAR(50) NULL,
    `status` ENUM('sent', 'failed', 'mock_sent') NOT NULL DEFAULT 'sent',
    `error_message` TEXT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_email_logs_user` (`recipient_user_id`),
    INDEX `idx_email_dedup` (`recipient_email`, `template_type`, `reference_id`, `reference_type`),
    INDEX `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. ANNOUNCEMENTS TABLES
-- Owner rent due & community announcements broadcasted to all or selected tenants.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `owner_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `due_date` DATE NULL,
    `target_type` ENUM('all', 'selected') NOT NULL DEFAULT 'all',
    `recipient_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_owner` (`owner_id`),
    CONSTRAINT `fk_announcements_owner` 
        FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_recipients` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `announcement_id` INT UNSIGNED NOT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `status` ENUM('sent', 'failed', 'mock_sent') NOT NULL DEFAULT 'sent',
    `error_message` TEXT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_announcement` (`announcement_id`),
    INDEX `idx_tenant` (`tenant_id`),
    CONSTRAINT `fk_ann_rec_ann` 
        FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ann_rec_tenant` 
        FOREIGN KEY (`tenant_id`) REFERENCES `users` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


