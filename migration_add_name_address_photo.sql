-- GradLink migration: middle name, extension name, structured PH address, profile photo
-- Run once in phpMyAdmin (SQL tab) on the `alumni_system` database. Safe to re-run (IF NOT EXISTS).
-- Works on MariaDB 10.4+.

-- 1) Middle name + extension name (Jr., Sr., III ...) for every account
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `middle_name` varchar(100) DEFAULT NULL AFTER `first_name`,
  ADD COLUMN IF NOT EXISTS `extension_name` varchar(20) DEFAULT NULL AFTER `last_name`;

-- 2) Alumni: structured address (the old `address` column is kept and now stores the full combined address)
ALTER TABLE `alumni_profiles`
  ADD COLUMN IF NOT EXISTS `province` varchar(100) DEFAULT NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `municipality` varchar(150) DEFAULT NULL AFTER `province`,
  ADD COLUMN IF NOT EXISTS `barangay` varchar(150) DEFAULT NULL AFTER `municipality`,
  ADD COLUMN IF NOT EXISTS `street_address` varchar(255) DEFAULT NULL AFTER `barangay`,
  ADD COLUMN IF NOT EXISTS `subdivision` varchar(255) DEFAULT NULL AFTER `street_address`;

-- 3) Partner: structured address (same as above)
ALTER TABLE `partner_profiles`
  ADD COLUMN IF NOT EXISTS `province` varchar(100) DEFAULT NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `municipality` varchar(150) DEFAULT NULL AFTER `province`,
  ADD COLUMN IF NOT EXISTS `barangay` varchar(150) DEFAULT NULL AFTER `municipality`,
  ADD COLUMN IF NOT EXISTS `street_address` varchar(255) DEFAULT NULL AFTER `barangay`,
  ADD COLUMN IF NOT EXISTS `subdivision` varchar(255) DEFAULT NULL AFTER `street_address`;

-- Profile pictures use existing columns:
--   alumni_profiles.profile_photo   (alumni picture)
--   partner_profiles.company_logo   (partner picture / company logo)
