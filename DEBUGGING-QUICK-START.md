# How to Use the New Debugging Features

## 1. Accessing the Migration Panel

Navigate to: **WordPress Admin > Invoices > Migration**

You will now see:

### Enhanced Migration Panel with Three Buttons:

```
┌─────────────────────────────────────────────────────────────┐
│                  CIG v5.0.0 - Database Migration            │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Migration Status                                           │
│  ─────────────────                                          │
│  Custom Tables: ✓ Created                                   │
│  Database Version: 5.0.0                                    │
│  Total Invoices: 26                                         │
│  Migrated: 0                                                │
│  Remaining: 26                                              │
│  Progress: 0%                                               │
│  [████████████░░░░░░░░░░░░] 0%                             │
│                                                              │
│  Migration Actions                                          │
│  ─────────────────                                          │
│  Click the button below to start migrating...              │
│                                                              │
│  [Start Migration]  [Test Single Invoice]  [View Logs]    │
│                                                              │
│  Migration Log                                              │
│  ─────────────────                                          │
│  (Initially hidden, shows after clicking any button)       │
│                                                              │
│  Debug Instructions (Expandable)                           │
│  ─────────────────                                          │
│  ▼ How to enable WordPress debug logging                   │
│     - wp-config.php instructions                           │
│     - Log file locations                                    │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## 2. Test Single Invoice (Recommended First Step)

**Click "Test Single Invoice"**

This will:
- Attempt to migrate just ONE invoice
- Show detailed output including:
  - Post ID being tested
  - Invoice data (DTO)
  - Any validation errors
  - Any database errors
  - Stack trace if exception occurs

### Example Success Output:

```
✓ Success! Invoice migrated successfully
  Post ID: 123
  
  ▼ Invoice Data
    {
      "post_id": 123,
      "invoice_number": "N25000001",
      "buyer_name": "John Doe",
      "buyer_tax_id": "1234567890",
      ...
    }
```

### Example Error Output:

```
✗ Error: Validation errors found
  Post ID: 123
  
  Validation Errors:
    - Buyer name is required
    - Buyer phone is required
  
  ▼ Invoice Data (with issues)
    {
      "post_id": 123,
      "invoice_number": "N25000001",
      "buyer_name": "",  ← Missing!
      "buyer_phone": "", ← Missing!
      ...
    }
```

## 3. View Migration Logs

**Click "View Migration Logs"**

This displays:
- WordPress debug.log (CIG entries only)
- WooCommerce logs (cig-*.log files)
- Color-coded by severity (red=error, yellow=warning, blue=info)

### Example Log Display:

```
WordPress debug.log
────────────────────
[2026-01-14 16:00:00] [CIG][INFO] Starting migration for invoice {"post_id":123}
[2026-01-14 16:00:00] [CIG][INFO] Creating invoice in custom table {"post_id":123}
[2026-01-14 16:00:00] [CIG][ERROR] Invoice validation failed {"post_id":123,"errors":["Buyer name is required"]}

WooCommerce Log: cig-2026-01-14.log
────────────────────────────────────
[CIG][ERROR] Failed to create DTO from postmeta {"post_id":123}
[CIG][INFO] Invoice migrated successfully {"post_id":124,"invoice_id":1}
```

## 4. Standalone Debug Utility (Alternative Access)

If WordPress admin is slow or inaccessible, use the standalone utility:

**URL Format:**
```
https://yoursite.com/wp-content/plugins/custom-woocommerce-invoice-generator/includes/admin/cig-debug-utility.php
```

**What you see:**

```
┌──────────────────────────────────────────────────────┐
│  🔍 CIG Migration Debug Utility                      │
├──────────────────────────────────────────────────────┤
│                                                       │
│  📊 Migration Status                                 │
│  ───────────────────                                 │
│  Status        │ ⚠ In Progress or Pending            │
│  Total Invoices│ 26                                  │
│  Migrated      │ 0                                   │
│  Remaining     │ 26                                  │
│  Progress      │ 0%                                  │
│  Custom Tables │ ✓ Created                           │
│  DB Version    │ 5.0.0                               │
│                                                       │
│  📝 WordPress Debug Log                              │
│  ────────────────────────                            │
│  [Log content displayed here...]                     │
│                                                       │
│  📋 WooCommerce Logs                                 │
│  ──────────────────────                              │
│  [Latest log: cig-2026-01-14.log]                   │
│  [Log content displayed here...]                     │
│                                                       │
│  ℹ️ System Information                               │
│  ─────────────────────                               │
│  PHP Version    │ 7.4.33                             │
│  WordPress      │ 6.4.2                              │
│  WooCommerce    │ 8.5.0                              │
│  CIG Version    │ 5.0.0                              │
│  WP_DEBUG       │ ✓ Enabled                          │
│  WP_DEBUG_LOG   │ ✓ Enabled                          │
│                                                       │
│  [Migration Panel] [WC Logs] [Refresh]              │
│                                                       │
└──────────────────────────────────────────────────────┘
```

## 5. Enable WordPress Debug Mode

**Before debugging, enable WordPress debug logging:**

1. Edit `wp-config.php` (in your WordPress root directory)
2. Add these lines **before** `/* That's all, stop editing! */`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
@ini_set('display_errors', 0);
```

3. Save the file
4. Errors will now be logged to `wp-content/debug.log`

**IMPORTANT:** After debugging, set `WP_DEBUG` back to `false`

## 6. Start Full Migration

Once testing passes:

1. **Click "Start Migration"**
2. Watch real-time log output:

```
✓ Batch completed: 10 migrated, 0 errors
✓ Batch completed: 10 migrated, 0 errors
✓ Batch completed: 6 migrated, 0 errors
✓ Migration completed!
```

If errors occur, you'll see:

```
✗ Error: Migration failed with exception: Duplicate entry 'N25000001'
  Error Type: PDOException
  File: /path/to/class-cig-invoice-repository.php:123
  
  ▼ Stack Trace
    #0 /path/to/repository.php(123): insert()
    #1 /path/to/migrator.php(152): create()
    ...
```

## Common Scenarios

### Scenario 1: Missing Required Fields

**What you see:**
```
✗ Validation errors found
  - Buyer name is required
  - Buyer phone is required
```

**Solution:**
1. Go to WordPress Admin > Invoices
2. Edit the invoice with the problem
3. Fill in the missing fields
4. Click "Test Single Invoice" again to verify
5. Click "Start Migration" when test passes

### Scenario 2: Database Error

**What you see:**
```
✗ Failed to create invoice in custom table
  Post ID: 123
```

**Solution:**
1. Click "View Migration Logs" to see database error details
2. Look for messages like:
   - "Duplicate entry" → Invoice already exists
   - "Foreign key constraint" → Invalid customer_id
3. Fix the underlying issue
4. Retry migration

### Scenario 3: 500 Internal Server Error

**What you see:**
```
✗ AJAX Error: error - Internal Server Error
  Response: <html>500 Internal Server Error</html>
```

**Solution:**
1. Check `wp-content/debug.log` for PHP errors
2. Look for fatal errors or memory issues
3. Possible causes:
   - PHP memory limit exceeded (increase in php.ini)
   - PHP timeout (increase max_execution_time)
   - Database connection lost
4. Fix the issue and retry

## Log File Locations

**WordPress Debug Log:**
- Path: `wp-content/debug.log`
- Access via: FTP, SSH, or cPanel File Manager
- Contains: All PHP errors, warnings, and CIG log entries

**WooCommerce Logs:**
- Path: `wp-content/uploads/wc-logs/`
- Filename: `cig-YYYY-MM-DD-*.log`
- Access via: WordPress Admin > WooCommerce > Status > Logs
- Contains: CIG-specific log entries via WC_Logger

## Quick Reference Commands

### Via Migration Panel:
- **Test One**: Click "Test Single Invoice"
- **View Logs**: Click "View Migration Logs"
- **Migrate All**: Click "Start Migration"

### Via Standalone Utility:
- Access directly: `[your-site]/wp-content/plugins/custom-woocommerce-invoice-generator/includes/admin/cig-debug-utility.php`

### Via File System:
- WordPress log: Download `wp-content/debug.log`
- WooCommerce logs: Download from `wp-content/uploads/wc-logs/`

### Via WP-CLI (Advanced):
```bash
# Test single invoice
wp eval 'var_dump(CIG()->migrator->migrate_single_invoice(123));'

# Check migration progress
wp eval 'var_dump(CIG()->migrator->get_migration_progress());'
```

## Need More Help?

See the complete guide: `MIGRATION-DEBUGGING-GUIDE.md`
