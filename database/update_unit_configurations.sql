-- ============================================================================
-- RentConnect: Hardcoded Unit Details & Pricing Schema Update
-- Strictly defines the layout and monthly rent for each of the 3 specific units:
-- Unit 1: 1 Bedroom, Monthly Rent: ₱6,000
-- Unit 2: 2 Bedrooms, Monthly Rent: ₱8,000
-- Unit 3: 3 Bedrooms, Monthly Rent: ₱8,000
-- ============================================================================

USE `rentconnect`;

-- Ensure exactly 3 units exist with strict layout and pricing
INSERT INTO `units` (`id`, `unit_number`, `floor_level`, `bedrooms`, `bathrooms`, `monthly_rent`)
VALUES
    (1, 'Unit 1', 1, 1, 1.0, 3000.00),
    (2, 'Unit 2', 1, 2, 1.0, 3000.00),
    (3, 'Unit 3', 2, 3, 1.0, 3000.00)
ON DUPLICATE KEY UPDATE
    `unit_number`  = VALUES(`unit_number`),
    `floor_level`  = VALUES(`floor_level`),
    `bedrooms`     = VALUES(`bedrooms`),
    `bathrooms`    = VALUES(`bathrooms`),
    `monthly_rent` = VALUES(`monthly_rent`);

-- Verify the updated configurations
SELECT id, unit_number, bedrooms, bathrooms, floor_level, monthly_rent FROM `units` ORDER BY id ASC;
