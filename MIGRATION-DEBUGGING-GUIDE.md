# Migration Debugging Guide

This guide provides detailed instructions for debugging migration issues in the Custom Invoice Generator (CIG) v5.0.0 plugin.

## Overview

The CIG v5.0.0 introduces custom database tables for improved performance. The migration process moves invoice data from WordPress postmeta to these custom tables. If you encounter issues during migration, this guide will help you diagnose and resolve them.

## Quick Start - Debugging Tools

### 1. Migration Panel (Primary Interface)

Access: **WordPress Admin > Invoices > Migration**

The migration panel now includes three debugging buttons:

- **Start Migration** - Begins the full migration process
- **Test Single Invoice** - Tests migration of one invoice with detailed error reporting
- **View Migration Logs** - Displays recent log entries from all sources

### 2. Debug Utility Script (Standalone Tool)

A standalone debug utility is available for when the WordPress admin is not accessible or you need a quick view of logs.

**Access URL:**
```
https://yoursite.com/wp-content/plugins/custom-woocommerce-invoice-generator/includes/admin/cig-debug-utility.php
```

**Features:**
- Migration status overview
- WordPress debug.log viewer (filtered for CIG entries)
- WooCommerce logs viewer
- System information
- Quick action links

**Requirements:**
- Must be logged in as an administrator
- Direct file access must be enabled on your server

### 3. Enable WordPress Debug Logging

To capture detailed error information, enable WordPress debug mode:

**Edit wp-config.php:**

Add these lines **before** `/* That's all, stop editing! Happy publishing. */`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
@ini_set('display_errors', 0);
```

**Important:** Set `WP_DEBUG` back to `false` after debugging to avoid performance issues.

**Log Location:**
- WordPress Log: `wp-content/debug.log`
- WooCommerce Logs: `wp-content/uploads/wc-logs/cig-*.log`

## Step-by-Step Debugging Process

### Step 1: Check Migration Status

1. Go to **Invoices > Migration** in WordPress admin
2. Review the migration status card showing:
   - Total invoices
   - Migrated count
   - Remaining count
   - Progress percentage

### Step 2: Test Single Invoice

Before running full migration, test with a single invoice:

1. Click **"Test Single Invoice"** button
2. Review the detailed output showing:
   - Invoice Post ID
   - DTO (Data Transfer Object) data
   - Validation errors (if any)
   - Database errors (if any)
   - Stack trace (if exception occurred)

**Common Issues Found:**
- Missing required fields (invoice_number, buyer_name, buyer_tax_id, buyer_phone)
- Invalid status values
- Database constraint violations
- PHP errors in data transformation

### Step 3: View Migration Logs

1. Click **"View Migration Logs"** button
2. Review logs from multiple sources:
   - WordPress debug.log (CIG-filtered entries)
   - WooCommerce logs (cig-*.log files)

**Look for:**
- `[CIG][ERROR]` entries
- `[CIG Migration Error]` entries
- Database error messages
- PHP warnings/notices

### Step 4: Run Full Migration

Once single invoice test passes:

1. Click **"Start Migration"** button
2. Monitor the real-time log output
3. If errors occur, they will be displayed with:
   - Error message
   - Error type (Exception class)
   - File and line number
   - Stack trace (expandable)

## Common Migration Errors and Solutions

### Error: "Validation errors found"

**Symptom:** Invoice fails validation

**Solution:**
1. Check which validation failed (shown in error details)
2. Common missing fields:
   - `invoice_number` - Check postmeta `_cig_invoice_number`
   - `buyer_name` - Check postmeta `_cig_buyer_name`
   - `buyer_tax_id` - Check postmeta `_cig_buyer_tax_id`
   - `buyer_phone` - Check postmeta `_cig_buyer_phone`
3. Update the invoice post in WordPress admin to add missing data
4. Retry migration

### Error: "Failed to create invoice in custom table"

**Symptom:** DTO created successfully but database insert fails

**Possible Causes:**
1. Database constraint violation
2. Duplicate invoice_number
3. Invalid foreign key (customer_id)
4. Database connection issue

**Solution:**
1. Check the detailed error in logs
2. Look for MySQL error messages
3. If duplicate `invoice_number`, check for existing records:
   ```sql
   SELECT * FROM {prefix}_cig_invoices WHERE invoice_number = 'N12345';
   ```
4. If foreign key issue, verify customer_id exists or set to NULL

### Error: "500 Internal Server Error"

**Symptom:** AJAX request fails with 500 error

**Solution:**
1. Enable WordPress debug logging (see above)
2. Check `wp-content/debug.log` for PHP errors
3. Common causes:
   - PHP memory limit exceeded
   - PHP timeout
   - Database connection lost
   - Fatal PHP error
4. View browser console (F12) for additional error details

### Error: "Migration stuck / not progressing"

**Symptom:** Migration shows same progress repeatedly

**Solution:**
1. Check if any invoice is causing repeated failures
2. Use "Test Single Invoice" to identify problematic invoice
3. Review that invoice's data in WordPress admin
4. Temporarily skip problematic invoice by manually marking it:
   ```php
   // In WordPress admin or via wp-cli
   update_post_meta($post_id, '_cig_migrated_v5', 1);
   ```
5. Continue migration, then fix skipped invoice later

## Using WP-CLI for Debugging (Advanced)

If you have WP-CLI access, you can debug migration programmatically:

```bash
# Check migration status
wp eval 'var_dump(CIG()->migrator->get_migration_progress());'

# Test migrating a specific invoice
wp eval 'var_dump(CIG()->migrator->migrate_single_invoice(123));'

# View validation errors for an invoice
wp eval '$dto = CIG_Invoice_DTO::from_postmeta(123); var_dump($dto->validate());'

# Check if tables exist
wp eval 'var_dump(CIG()->database->tables_exist());'
```

## Log Format Reference

### CIG Logger Format

```
[CIG][LEVEL] Message {"context":"data"}
```

**Levels:**
- `DEBUG` - Detailed debugging information
- `INFO` - Informational messages
- `WARNING` - Warning messages
- `ERROR` - Error messages

### Migration-Specific Log Patterns

```
[CIG][INFO] Starting migration for invoice {"post_id":123}
[CIG][INFO] Creating invoice in custom table {"post_id":123}
[CIG][INFO] Invoice created successfully {"post_id":123,"invoice_id":456}
[CIG][INFO] Migrating invoice items {"post_id":123,"item_count":5}
[CIG][ERROR] Invoice validation failed {"post_id":123,"errors":["Buyer name is required"]}
[CIG][ERROR] Migration exception {"post_id":123,"error":"Database error message","file":"/path/to/file.php","line":123}
```

## Getting Additional Help

If you continue to experience issues after following this guide:

1. **Collect Information:**
   - Migration status (from Migration Panel)
   - Test single invoice output (copy full output)
   - Recent log entries (last 50-100 lines from debug.log and wc logs)
   - System information (PHP version, WordPress version, WooCommerce version)

2. **Access Debug Utility:**
   - Use the standalone debug utility script (URL above)
   - Take screenshots of all sections

3. **Report Issue:**
   - Include all collected information
   - Specify exact error messages
   - Note when the error occurs (which invoice, after how many migrated)

## Security Note

Remember to:
- Disable `WP_DEBUG` after debugging
- Secure access to `cig-debug-utility.php` (requires admin login)
- Do not share log files publicly (may contain sensitive data)
- Consider backup before migration

## Files Modified for Debugging

This enhancement adds/modifies:

1. `includes/admin/class-cig-migration-admin.php`
   - Enhanced error handling in AJAX handlers
   - New endpoints: `ajax_get_migration_logs`, `ajax_test_single_invoice`
   - Log reading functionality

2. `includes/migration/class-cig-migrator.php`
   - Detailed logging at each migration step
   - Comprehensive error catching (Exception and Error)
   - Validation error reporting

3. `templates/admin/migration-panel.php`
   - New UI buttons for debugging
   - Enhanced JavaScript for error display
   - Debug instructions section

4. `includes/admin/cig-debug-utility.php` (NEW)
   - Standalone debug utility script
   - Log viewing interface
   - System status overview
