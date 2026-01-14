# Migration Debugging Solution - Quick Reference

## Problem Statement

The migration process was stuck with a 500 Internal Server Error, and there was no way to see detailed error logs or debug the specific issue causing the migration to fail.

## Solution Implemented

### 1. Enhanced Error Handling and Logging

**File: `includes/admin/class-cig-migration-admin.php`**
- Added comprehensive try-catch blocks in `ajax_migrate_batch()` to catch both Exception and Error types
- Added detailed error information in AJAX responses including:
  - Error message
  - Error type (class name)
  - File and line number where error occurred
  - Full stack trace
- Created new AJAX endpoints:
  - `cig_get_migration_logs` - Retrieves and displays logs from multiple sources
  - `cig_test_single_invoice` - Tests migration of one invoice with detailed debugging output
- Added helper methods for log file reading:
  - `get_migration_logs()` - Aggregates logs from WordPress and WooCommerce
  - `get_wc_log_files()` - Finds WooCommerce log files
  - `read_log_file()` - Reads and filters log files efficiently

**File: `includes/migration/class-cig-migrator.php`**
- Enhanced `migrate_single_invoice()` with step-by-step logging:
  - Log at start of migration
  - Log DTO creation
  - Log validation errors with full details
  - Log database operations
  - Log item migration
  - Log payment migration
- Added try-catch for both Exception and Error (PHP 7+ fatal errors)
- Added validation error logging before migration attempt

### 2. Enhanced User Interface

**File: `templates/admin/migration-panel.php`**
- Added three action buttons:
  - **Test Single Invoice** - Tests one invoice before full migration
  - **View Migration Logs** - Displays aggregated logs
  - **Start Migration** - Existing button, now with better error display
- Enhanced JavaScript to display:
  - Success messages with green styling
  - Error messages with red styling
  - Detailed error information (type, file, line, trace)
  - AJAX errors with response content
  - Expandable stack traces
  - Formatted JSON data
- Added comprehensive CSS styles for different log types:
  - `.log-success` - Green, for successful operations
  - `.log-error` - Red, for errors
  - `.log-error-detail` - Yellow, for error details
  - `.log-warning` - Yellow, for warnings
  - `.log-info` - Blue, for informational messages
- Added debug instructions section with:
  - How to enable WP_DEBUG
  - Where to find logs
  - Code snippets for wp-config.php

### 3. Standalone Debug Utility

**File: `includes/admin/cig-debug-utility.php` (NEW)**

A standalone PHP script that can be accessed directly via browser for quick debugging access.

**Features:**
- Migration status overview table
- WordPress debug.log viewer (filtered for CIG entries)
- WooCommerce logs viewer (shows most recent)
- System information table
- Quick action links
- Color-coded log display (errors in red, warnings in yellow)
- No WordPress admin navigation required (useful if admin is slow/broken)

**Access URL:**
```
https://yoursite.com/wp-content/plugins/custom-woocommerce-invoice-generator/includes/admin/cig-debug-utility.php
```

### 4. Documentation

**File: `MIGRATION-DEBUGGING-GUIDE.md` (NEW)**

Comprehensive documentation including:
- Overview of debugging tools
- Step-by-step debugging process
- Common errors and solutions
- WP-CLI commands for advanced debugging
- Log format reference
- Security considerations

## How to Use

### Quick Debug Steps:

1. **Enable WordPress Debug Logging** (if not already enabled):
   ```php
   // Add to wp-config.php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   @ini_set('display_errors', 0);
   ```

2. **Test Single Invoice**:
   - Go to: WordPress Admin > Invoices > Migration
   - Click "Test Single Invoice" button
   - Review detailed output to identify specific errors

3. **View Logs**:
   - Click "View Migration Logs" button to see aggregated logs
   - Or access standalone debug utility directly

4. **Fix Issues** based on error messages:
   - Validation errors → Update invoice data
   - Database errors → Check constraints, duplicates
   - PHP errors → Review stack trace, check PHP version

5. **Retry Migration** once issues are resolved

### Log Locations:

- **WordPress Log**: `wp-content/debug.log`
- **WooCommerce Logs**: `wp-content/uploads/wc-logs/cig-*.log`
- **Via Admin**: WooCommerce > Status > Logs > cig-*.log

## Key Improvements

1. **Visibility**: Can now see exact errors during migration
2. **Granularity**: Can test single invoice before full migration
3. **Accessibility**: Multiple ways to access logs (panel, standalone script, file system)
4. **Context**: Errors include file, line, trace, and context data
5. **User-Friendly**: Clear instructions and color-coded messages
6. **Non-Disruptive**: Can debug without affecting the site

## Technical Details

### Error Capture Strategy:

```php
try {
    // Migration code
} catch (Exception $e) {
    // Catches standard exceptions
    error_log('[CIG Migration Error] ' . $e->getMessage());
    wp_send_json_error([...detailed info...]);
} catch (Error $e) {
    // Catches PHP 7+ fatal errors
    error_log('[CIG Migration Fatal Error] ' . $e->getMessage());
    wp_send_json_error([...detailed info...]);
}
```

### Log Aggregation:

The system reads logs from multiple sources and presents them in one interface:
- WordPress debug.log (filtered for CIG entries)
- WooCommerce logs (cig-*.log files)
- Displayed with syntax highlighting and formatting

### Validation Before Migration:

```php
$validation_errors = $invoice_dto->validate();
if (!empty($validation_errors)) {
    // Log and report validation errors
    // Prevents database errors
}
```

## Benefits

- **Reduced Debugging Time**: Immediate visibility into errors
- **Better User Experience**: Clear, actionable error messages
- **Preventive**: Test single invoice before batch migration
- **Comprehensive**: Multiple debugging tools for different scenarios
- **Professional**: Follows WordPress and WooCommerce logging standards

## Files Changed

1. `includes/admin/class-cig-migration-admin.php` - Enhanced with debugging endpoints
2. `includes/migration/class-cig-migrator.php` - Added detailed logging
3. `templates/admin/migration-panel.php` - New UI and JavaScript
4. `includes/admin/cig-debug-utility.php` - NEW standalone debug tool
5. `MIGRATION-DEBUGGING-GUIDE.md` - NEW comprehensive documentation
