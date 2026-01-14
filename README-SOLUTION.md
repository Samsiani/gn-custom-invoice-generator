# 🎯 SOLUTION: Migration Debugging Features Now Available!

## What Was The Problem?

You reported that the migration was stuck with a 500 Internal Server Error in the browser console, and you couldn't see detailed logs to diagnose the issue. You needed:

1. ✅ A way to view detailed PHP errors
2. ✅ A way to see database constraint violations
3. ✅ Access to CIG_Logger output
4. ✅ Access to WordPress debug.log for migration
5. ✅ A debug script to check migration status

## What I've Added

### 🆕 Three New Buttons in Migration Panel

Navigate to: **WordPress Admin > Invoices > Migration**

You'll now see three powerful debugging buttons:

#### 1. **Test Single Invoice** Button
- Click this FIRST before running full migration
- Tests just ONE invoice and shows exactly what's wrong
- Displays:
  - ✅ Validation errors (missing fields, wrong formats)
  - ✅ Database errors (constraints, duplicates)
  - ✅ Stack traces (where the code failed)
  - ✅ Invoice data (so you can see what's missing)

**This will tell you exactly why your migration is failing!**

#### 2. **View Migration Logs** Button
- Shows recent log entries from:
  - WordPress debug.log (filtered for CIG)
  - WooCommerce logs (cig-*.log files)
- Color-coded (red=error, yellow=warning, blue=info)
- Updates in real-time

#### 3. **Start Migration** Button (Enhanced)
- Now shows detailed errors if migration fails
- Shows stack traces and file/line numbers
- You can retry after fixing issues

### 🔧 Standalone Debug Utility Script

**Access directly via browser:**
```
https://yoursite.com/wp-content/plugins/custom-woocommerce-invoice-generator/includes/admin/cig-debug-utility.php
```

This standalone page shows:
- ✅ Migration status overview
- ✅ WordPress debug.log viewer
- ✅ WooCommerce logs viewer
- ✅ System information
- ✅ Quick action links

**Use this if WordPress admin is slow or not working!**

### 📚 Complete Documentation

Three comprehensive guides have been added:

1. **`DEBUGGING-QUICK-START.md`** - Visual quick start with examples
2. **`MIGRATION-DEBUGGING-GUIDE.md`** - Complete debugging reference
3. **`SOLUTION-SUMMARY.md`** - Technical details of the solution

## 🚀 How To Debug Your Migration (Step-by-Step)

### Step 1: Enable WordPress Debug Mode

Edit your `wp-config.php` file and add these lines **before** `/* That's all, stop editing! */`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
@ini_set('display_errors', 0);
```

Save the file. Errors will now be logged to `wp-content/debug.log`

### Step 2: Test Single Invoice

1. Go to: **WordPress Admin > Invoices > Migration**
2. Click **"Test Single Invoice"** button
3. Look at the output:

**If you see validation errors:**
```
✗ Error: Validation errors found
  - Buyer name is required
  - Buyer phone is required
```

**Solution:** Go to that invoice in WordPress and fill in the missing fields.

**If you see database errors:**
```
✗ Failed to create invoice in custom table
```

**Solution:** Click "View Migration Logs" to see the exact database error message.

### Step 3: View Migration Logs

1. Click **"View Migration Logs"** button
2. Look for red error messages
3. Common errors you might see:
   - "Duplicate entry" → Invoice already exists
   - "Foreign key constraint" → Invalid customer_id
   - "Column cannot be null" → Missing required field

### Step 4: Fix Issues and Retry

1. Fix the issues identified in steps 2-3
2. Click **"Test Single Invoice"** again to verify the fix
3. Once it passes, click **"Start Migration"** to migrate all invoices

### Step 5: Monitor Full Migration

During full migration, you'll see:
```
✓ Batch completed: 10 migrated, 0 errors
✓ Batch completed: 10 migrated, 0 errors
✓ Batch completed: 6 migrated, 0 errors
✓ Migration completed!
```

If errors occur, they'll be shown with full details.

## 📍 Log File Locations

### WordPress Debug Log
- **Path:** `wp-content/debug.log`
- **Access:** Via FTP, SSH, or cPanel File Manager
- **Contains:** All PHP errors and CIG log entries

### WooCommerce Logs
- **Path:** `wp-content/uploads/wc-logs/cig-*.log`
- **Access:** WordPress Admin > WooCommerce > Status > Logs
- **Contains:** CIG-specific log entries

## 🔍 What To Look For In Logs

### Validation Errors
```
[CIG][ERROR] Invoice validation failed {"post_id":123,"errors":["Buyer name is required"]}
```
**Fix:** Update the invoice to add the missing buyer name

### Database Errors
```
[CIG][ERROR] Failed to create invoice in custom table {"post_id":123}
```
**Fix:** Check the next log line for the specific database error

### PHP Errors
```
PHP Fatal error: Call to undefined function in /path/to/file.php on line 123
```
**Fix:** This indicates a code issue - report this with the error details

## 💡 Common Issues and Solutions

### Issue 1: "Buyer name is required"
**Cause:** Invoice has empty buyer_name field
**Solution:**
1. Go to WordPress Admin > Invoices
2. Find and edit the invoice
3. Fill in the buyer name field
4. Save and retry migration

### Issue 2: "Duplicate entry 'N25000001'"
**Cause:** Invoice number already exists in database
**Solution:**
1. Check if invoice was already migrated
2. Look for duplicate invoice numbers in your invoices
3. Use "View Migration Logs" to see which invoice has the duplicate

### Issue 3: "500 Internal Server Error"
**Cause:** PHP error (memory, timeout, or fatal error)
**Solution:**
1. Check `wp-content/debug.log` for PHP errors
2. Look for "Fatal error" or "Memory exhausted"
3. Increase PHP memory limit if needed
4. Contact hosting support if errors persist

## 🎁 Bonus: Advanced Debugging (WP-CLI)

If you have WP-CLI access:

```bash
# Test single invoice
wp eval 'var_dump(CIG()->migrator->migrate_single_invoice(123));'

# Check progress
wp eval 'var_dump(CIG()->migrator->get_migration_progress());'

# View validation errors for an invoice
wp eval '$dto = CIG_Invoice_DTO::from_postmeta(123); var_dump($dto->validate());'
```

## 📞 Need More Help?

If you're still stuck after trying these steps:

1. **Click "Test Single Invoice"** and copy the full output
2. **Click "View Migration Logs"** and copy recent error entries
3. **Access the standalone debug utility** and take a screenshot
4. **Check `wp-content/debug.log`** for PHP errors
5. Share all of the above when asking for help

## ✨ What Makes This Solution Special

✅ **Multiple debugging methods** - UI buttons, standalone script, log files
✅ **Test before migrate** - Catch issues before running full migration
✅ **Detailed error messages** - Exact file, line, and stack trace
✅ **Validation checking** - See which fields are missing or invalid
✅ **Aggregated logs** - All logs in one place
✅ **User-friendly** - Clear instructions and color-coded output
✅ **Secure** - All outputs properly sanitized (XSS protection)
✅ **Performance optimized** - Efficient log reading

## 🔐 Security Note

- Remember to disable `WP_DEBUG` after debugging (set to `false`)
- Don't share log files publicly (they may contain sensitive data)
- The standalone debug utility requires admin login

## ✅ Summary

**Before:** Migration stuck with 500 error, no way to see what's wrong

**After:** 
- ✅ Test single invoice with detailed errors
- ✅ View all logs in one place
- ✅ See exact validation/database errors
- ✅ Step-by-step debugging guide
- ✅ Multiple ways to access logs
- ✅ Professional debugging interface

**Your migration will now show you exactly what's wrong and how to fix it!**

---

*For complete technical details, see:*
- *`DEBUGGING-QUICK-START.md` - Quick visual guide*
- *`MIGRATION-DEBUGGING-GUIDE.md` - Complete reference*
- *`SOLUTION-SUMMARY.md` - Technical implementation details*
