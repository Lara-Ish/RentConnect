-- ============================================================================
-- RentConnect: Complete Database Reset Script
-- Completely wipes all records from maintenance_tickets, payments, and users tables
-- Resets AUTO_INCREMENT counters back to 1 so accounts can be created from scratch.
-- ============================================================================

USE `rentconnect`;

-- Method 1: TRUNCATE TABLE (Recommended: Fast & Resets AUTO_INCREMENT)
-- Disable foreign key checks to allow truncating parent table 'users'
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `maintenance_tickets`;
TRUNCATE TABLE `payments`;
TRUNCATE TABLE `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Optional: Re-create Primary Landlord Account (Lead Dimaculangan)
-- Username: admin
-- Password: admin123
-- ============================================================================
-- INSERT INTO `users` (
--     `id`, `unit_id`, `username`, `name`, `email`, `password_hash`, `role`, 
--     `phone`, `birthday`, `emergency_contact`, `move_in_date`, `is_active`, `created_at`
-- ) VALUES (
--     1, NULL, 'admin', 'Lead Dimaculangan', 'rentconnect2026@gmail.com', 
--     '$2y$10$L1oJw/e9iaOxHBBWA6p0G.EEPbgJ8jKQxXLEnjzxwY0DSzno7igyO', 'landlord', 
--     '+63 917 123 4567', '1985-11-20', 'Property Management Office', '2024-01-01', 1, NOW()
-- );
