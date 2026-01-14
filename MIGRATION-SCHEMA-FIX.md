# Migration Schema Fix - Technical Documentation

## Problem Summary

The migration system was failing with database errors:
```
Unknown column 'post_id' in 'WHERE'
Unknown column 'buyer_name' in 'SET'
```

These errors occurred when attempting to migrate invoice data from WordPress postmeta to custom database tables.

## Root Cause

The custom database tables (`wp_cig_invoices`, `wp_cig_invoice_items`, `wp_cig_payments`) were created with an older schema version that was missing critical columns. The current code expected columns that didn't exist in the database.

WordPress's `dbDelta()` function, which is used to create tables, has limitations:
- It creates new tables correctly
- It can add new columns in some cases
- **It does NOT reliably update existing table schemas** when columns are missing

This meant that sites with older versions of the tables had incomplete schemas.

## Solution Overview

Implemented a comprehensive schema migration system that:

1. **Detects schema version** using the `cig_db_version` option
2. **Creates tables if they don't exist** (for new installations)
3. **Migrates existing tables** by adding missing columns (for upgrades)
4. **Uses ALTER TABLE** statements to add missing columns dynamically
5. **Checks for column existence** before attempting to add them
6. **Handles indexes separately** to avoid errors

## Implementation Details

### 1. Schema Migration Entry Point

**File:** `includes/database/class-cig-database.php`

```php
public function maybe_migrate_schema() {
    $current_version = get_option(self::DB_VERSION_OPTION, '0.0.0');
    
    if (version_compare($current_version, self::DB_VERSION, '<')) {
        // Create tables (safe - uses dbDelta)
        $this->create_tables();
        
        // Migrate schema (adds missing columns)
        return $this->migrate_schema_to_current();
    }
    
    return true;
}
```

### 2. Column Detection and Addition

The `migrate_schema_to_current()` method:
- Checks if each table exists
- For each required column, checks if it exists using `column_exists()`
- If missing, adds the column using `ALTER TABLE`
- Adds indexes separately (with error tolerance)

Example for adding a single column:
```php
if (!$this->column_exists($table_invoices, 'buyer_name')) {
    $result = $this->wpdb->query(
        "ALTER TABLE `{$table_invoices}` 
         ADD COLUMN `buyer_name` varchar(255) DEFAULT NULL 
         AFTER `invoice_number`"
    );
}
```

### 3. Hook Integration

**File:** `custom-woocommerce-invoice-generator.php`

The schema migration is triggered in two places:

#### On Plugin Activation
```php
public function activate() {
    $database = new CIG_Database();
    $database->create_tables();
    $database->maybe_migrate_schema();
    // ... other activation tasks
}
```

#### On Admin Init (for existing installations)
```php
add_action('admin_init', function() {
    // Only run if version is outdated (uses cached option)
    $current_version = get_option('cig_db_version', '0.0.0');
    if (version_compare($current_version, '5.0.0', '<')) {
        $this->database->maybe_migrate_schema();
    }
}, 5);
```

The `admin_init` check ensures:
- Migration runs automatically for existing installations
- Only executes when version is outdated (lightweight check)
- Uses WordPress option caching (minimal performance impact)

## Columns Added by Migration

### Invoices Table (`wp_cig_invoices`)
- `post_id` - Reference to WordPress post
- `buyer_name` - Customer name
- `buyer_tax_id` - Tax identification number
- `buyer_address` - Customer address
- `buyer_phone` - Contact phone
- `buyer_email` - Contact email
- `customer_id` - Link to customer record
- `invoice_status` - Invoice type (standard/proforma/etc)
- `lifecycle_status` - Workflow status
- `rs_uploaded` - Revenue service upload flag
- `subtotal` - Pre-tax amount
- `tax_amount` - Tax amount
- `discount_amount` - Discount applied
- `total_amount` - Final total
- `paid_amount` - Amount paid
- `balance` - Remaining balance
- `general_note` - Notes field
- `created_by` - User who created
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp

### Items Table (`wp_cig_invoice_items`)
- `product_id` - WooCommerce product ID
- `product_name` - Product name
- `product_sku` - Product SKU
- `quantity` - Item quantity
- `unit_price` - Price per unit
- `line_total` - Total for line
- `warranty` - Warranty period
- `item_note` - Item-specific notes
- `sort_order` - Display order
- `created_at` - Creation timestamp

### Payments Table (`wp_cig_payments`)
- `payment_date` - When payment was made
- `payment_method` - Payment method used
- `amount` - Payment amount
- `transaction_ref` - Transaction reference
- `note` - Payment notes
- `created_by` - User who recorded payment
- `created_at` - Record creation timestamp

## Safety Measures

### SQL Injection Prevention
- Table names are validated through `get_table_name()` which only returns predefined table names
- Column definitions are hardcoded in the migration method (not user-supplied)
- Column existence checks use prepared statements with `wpdb->prepare()`

### Error Handling
- Column addition failures are tracked and reported
- Index creation failures are tolerated (indexes may already exist)
- Migration only marks success if column additions succeed
- Version option is only updated on successful migration

### Default Values
All NOT NULL columns use DEFAULT values to handle existing rows:
- Numeric fields: `DEFAULT 0` or `DEFAULT 0.00`
- String fields: `DEFAULT ''` or `DEFAULT NULL`
- DateTime fields: `DEFAULT CURRENT_TIMESTAMP`

## Testing

### Unit Test
Created `/tmp/test-schema-migration.php` that validates:
- ✓ Migration detects missing columns
- ✓ All 22 required columns are added
- ✓ Proper ALTER TABLE queries are generated
- ✓ Version option is updated after successful migration

### Test Results
```
=== Schema Migration Test ===
1. Checking initial state...
   Invoices table columns: id, invoice_number

2. Running maybe_migrate_schema()...
   Result: SUCCESS

3. Checking final state...
   Invoices table columns: id, invoice_number, post_id, buyer_name, 
   buyer_tax_id, buyer_address, buyer_phone, buyer_email, customer_id, 
   invoice_status, lifecycle_status, rs_uploaded, subtotal, tax_amount, 
   discount_amount, total_amount, paid_amount, balance, general_note, 
   created_by, created_at, updated_at

4. Verifying required columns exist...
   ✓ All required columns present!
```

## Impact

### Before Fix
- Migration failed with "Unknown column" errors
- Invoices could not be migrated from postmeta to custom tables
- Users stuck with old postmeta-based system

### After Fix
- Schema automatically upgrades on plugin activation
- Existing installations upgrade on next admin access
- Migration completes successfully
- All invoice data can be migrated to custom tables

## Performance Considerations

1. **One-time operation**: Migration only runs when version is outdated
2. **Cached version check**: Uses WordPress option caching
3. **Incremental updates**: Only adds missing columns, doesn't touch existing ones
4. **Index creation**: Separated from column creation for resilience

## Backwards Compatibility

- New installations: Tables created with full schema from start
- Existing installations: Schema upgraded automatically
- No data loss: DEFAULT values handle existing rows
- Postmeta fallback: Code still supports reading from postmeta if needed

## Files Modified

1. `includes/database/class-cig-database.php`
   - Added `migrate_schema_to_current()` method
   - Added `column_exists()` helper method
   - Added `index_exists()` helper method
   - Updated `maybe_migrate_schema()` to call migration
   - Added comprehensive column definitions for all three tables

2. `custom-woocommerce-invoice-generator.php`
   - Updated `activate()` to call `maybe_migrate_schema()`
   - Added `admin_init` hook with version check
   - Added comments documenting the approach

## Future Enhancements

Potential improvements for future versions:

1. **Migration logging**: Log each column addition for debugging
2. **Rollback capability**: Store old schema for rollback if needed
3. **Progress tracking**: Show progress in admin for large migrations
4. **Batch processing**: Split migration into chunks for large sites
5. **Schema versioning**: Add more granular version tracking (5.0.1, 5.0.2, etc)

## Maintenance Notes

When adding new columns in future versions:

1. Update the schema in `create_tables()` method
2. Add column definition to `migrate_schema_to_current()` method
3. Increment `DB_VERSION` constant
4. Test with both fresh install and upgrade scenarios
5. Document the change in release notes
