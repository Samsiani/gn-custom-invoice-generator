# Migration Schema Refactoring - Implementation Guide

## Overview
This document explains the changes made to fix the migration schema mismatch and provides instructions for restarting the migration.

## Problem Summary
The actual database tables created by a previous migration had different column names than what the current code expected:

### Table Column Mismatches

#### wp_cig_invoices
- `old_post_id` (actual) vs `post_id` (expected)
- `author_id` (actual) vs `created_by` (expected)
- `type` (actual) vs `invoice_status` (expected)
- `status` (actual) vs `lifecycle_status` (expected)

#### wp_cig_invoice_items
- `qty` (actual) vs `quantity` (expected)
- `price` (actual) vs `unit_price` (expected)
- `total` (actual) vs `line_total` (expected)
- `reservation_expires_at` (exists in actual, missing in expected)

#### wp_cig_payments
- `user_id` (actual) vs `created_by` (expected)
- `date` (actual, type: date) vs `payment_date` (expected, type: datetime)

## Changes Implemented

### 1. DTO Updates
All DTOs have been updated to use the actual column names:

#### CIG_Invoice_DTO
```php
// Old properties
public $post_id;
public $created_by;
public $invoice_status;
public $lifecycle_status;

// New properties
public $old_post_id;
public $author_id;
public $type;
public $status;
```

**Backward Compatibility**: The `from_array()` method accepts both old and new column names to ensure compatibility with existing code.

#### CIG_Invoice_Item_DTO
```php
// Old properties
public $quantity;
public $unit_price;
public $line_total;

// New properties
public $qty;
public $price;
public $total;
public $reservation_expires_at;  // New field
```

#### CIG_Payment_DTO
```php
// Old properties
public $created_by;
public $payment_date;  // datetime

// New properties
public $user_id;
public $date;  // date type (YYYY-MM-DD)
```

**Note**: The payment date conversion includes validation to handle invalid dates gracefully.

### 2. Repository Updates

#### CIG_Invoice_Repository
- Fixed `find_by_post_id()` to handle null results without fatal errors
- Updated all queries to use `old_post_id` instead of `post_id`
- Updated cache keys to use new column names

#### CIG_Payment_Repository
- Updated ordering to use `date` instead of `payment_date`

### 3. Service Layer Updates

#### CIG_Invoice_Service
Updated all direct property access:
- `$invoice->post_id` → `$invoice->old_post_id`
- `$invoice->created_by` → `$invoice->author_id`
- `$invoice->invoice_status` → `$invoice->type`
- `$invoice->lifecycle_status` → `$invoice->status`

### 4. Database Schema Updates

#### CIG_Database Class
- Updated `CREATE TABLE` statements to use actual column names
- Updated migration schema additions
- Fixed MySQL compatibility for date columns

### 5. Validation Updates
Added `'reserved'` to the list of valid lifecycle statuses in `CIG_Invoice_DTO::validate()`.

## Restarting the Migration

### Prerequisites
1. Backup your database before proceeding
2. Ensure you have database access

### Step 1: Clear Existing Data
Use the provided SQL script to clear the custom tables:

```bash
# Option A: Via MySQL command line
mysql -u [username] -p [database_name] < clear-migration-tables.sql

# Option B: Via phpMyAdmin or other database tool
# Run the SQL commands from clear-migration-tables.sql
```

The script will:
1. Truncate all three custom tables (payments, items, invoices)
2. Display counts to verify tables are empty
3. Optionally clear migration status flags

**Important**: The script includes commented-out sections to clear migration flags. Uncomment these if you want to completely reset the migration status.

### Step 2: Verify Table Structure
Check that the actual table structure matches the expected structure:

```sql
-- Check invoices table
DESCRIBE wp_cig_invoices;

-- Check invoice items table
DESCRIBE wp_cig_invoice_items;

-- Check payments table
DESCRIBE wp_cig_payments;
```

### Step 3: Run Migration
After clearing the tables and verifying the structure, run the migration process:

1. Go to WordPress Admin Dashboard
2. Navigate to the Invoice Generator migration page
3. Click "Start Migration" or "Resume Migration"
4. Monitor the progress

### Step 4: Verify Results
After migration completes, verify:

```sql
-- Check record counts
SELECT 'Invoices' as table_name, COUNT(*) as count FROM wp_cig_invoices
UNION ALL
SELECT 'Items', COUNT(*) FROM wp_cig_invoice_items
UNION ALL
SELECT 'Payments', COUNT(*) FROM wp_cig_payments;

-- Check for orphaned records
SELECT 'Orphaned Items' as type, COUNT(*) as count
FROM wp_cig_invoice_items i
LEFT JOIN wp_cig_invoices inv ON i.invoice_id = inv.id
WHERE inv.id IS NULL

UNION ALL

SELECT 'Orphaned Payments', COUNT(*)
FROM wp_cig_payments p
LEFT JOIN wp_cig_invoices inv ON p.invoice_id = inv.id
WHERE inv.id IS NULL;
```

Expected results:
- Invoices: 26 records
- Items: 53 records
- Payments: 22 records
- Orphaned Items: 0
- Orphaned Payments: 0

## Backward Compatibility

The implementation maintains backward compatibility:

1. **DTO from_array() methods** accept both old and new column names
2. **Postmeta fallback methods** continue to work with old naming conventions
3. **Migrator code** uses old names when constructing arrays but DTOs handle conversion

This means:
- Existing code that passes data with old column names will still work
- Migration from postmeta will work correctly
- New code can use either naming convention

## Troubleshooting

### Issue: Migration fails with "Invalid lifecycle status"
**Solution**: The status value from your database might not be in the valid list. Check the actual values:

```sql
SELECT DISTINCT status FROM wp_cig_invoices;
```

Add any missing statuses to the `$valid_lifecycle` array in `CIG_Invoice_DTO::validate()`.

### Issue: Payment dates are not displaying correctly
**Solution**: Ensure dates are being stored in YYYY-MM-DD format. The DTO automatically converts datetime to date format, but check the source data:

```sql
SELECT id, date, created_at FROM wp_cig_payments LIMIT 5;
```

### Issue: Fatal error "Call to member function on null"
**Solution**: This was fixed in `find_by_post_id()`. Make sure you're using the updated code. If you still get this error, check the stack trace to identify where the null is coming from.

## Testing Checklist

- [ ] Database tables cleared successfully
- [ ] Table structure verified (correct column names)
- [ ] Migration completed without errors
- [ ] All 26 invoices migrated
- [ ] All 53 invoice items migrated
- [ ] All 22 payments migrated
- [ ] No orphaned records
- [ ] Invoice details display correctly in admin
- [ ] Invoice PDF generation works
- [ ] Payment history displays correctly
- [ ] Statistics/reports show correct data

## Support

If you encounter issues:

1. Check the WordPress error logs
2. Check the migration logs (if enabled)
3. Verify database table structure matches expectations
4. Ensure all files are updated to the latest version
5. Check for any custom code that might be using old property names

## Files Changed

1. `includes/dto/class-cig-invoice-dto.php`
2. `includes/dto/class-cig-invoice-item-dto.php`
3. `includes/dto/class-cig-payment-dto.php`
4. `includes/repositories/class-cig-invoice-repository.php`
5. `includes/repositories/class-cig-payment-repository.php`
6. `includes/database/class-cig-database.php`
7. `includes/services/class-cig-invoice-service.php`
8. `clear-migration-tables.sql` (new file)
