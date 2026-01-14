-- SQL Script to Clear Migration Tables
-- This script safely clears the custom tables to restart migration
-- WARNING: This will delete all data from the custom tables!
-- Run this script only if you are certain you want to restart the migration

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Clear the payments table first (child table)
TRUNCATE TABLE `wp_cig_payments`;

-- Clear the invoice items table (child table)
TRUNCATE TABLE `wp_cig_invoice_items`;

-- Clear the invoices table (parent table)
TRUNCATE TABLE `wp_cig_invoices`;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Optional: Reset migration status flags in WordPress options
-- DELETE FROM `wp_options` WHERE `option_name` = 'cig_migration_status';
-- DELETE FROM `wp_options` WHERE `option_name` = 'cig_migration_progress';

-- Optional: Clear migration flags from post meta
-- DELETE FROM `wp_postmeta` WHERE `meta_key` = '_cig_migrated_v5';

-- Verify tables are empty
SELECT 'Invoices count:' as info, COUNT(*) as count FROM `wp_cig_invoices`
UNION ALL
SELECT 'Items count:' as info, COUNT(*) as count FROM `wp_cig_invoice_items`
UNION ALL
SELECT 'Payments count:' as info, COUNT(*) as count FROM `wp_cig_payments`;
