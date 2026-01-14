# Activation Date Implementation - Updated Approach

## Overview

This document describes the implementation of the **Creation Date Update Logic** for accurate financial reporting in the Custom Invoice Generator system. The system now **updates the actual creation date** (`created_at` and `post_date`) when an invoice transitions from "Fictive" (draft) status to "Standard" (active) status, ensuring that all native WordPress filters and statistical queries automatically align with the actual date of sale.

## Problem Statement

The previous implementation using a separate `activation_date` field did not fully resolve the discrepancy in reporting:

1. Statistical filters and the Admin Dashboard still frequently default to the original `post_date` (Creation Date)
2. Fictive invoices appeared in the wrong financial period after being finalized
3. Complex COALESCE logic was needed in SQL queries to handle both dates
4. Native WordPress queries couldn't easily use the activation date

## New Solution

**Instead of maintaining a separate activation_date field, the system now updates the primary timestamp** of the invoice when its status changes:

1. When an invoice transitions from Fictive to Active (Sold/Reserved), the system updates:
   - `created_at` in the custom table
   - `post_date` in the WordPress database
   
2. The `activation_date` field is now used as a **flag** to track whether an invoice has been activated (preventing multiple date updates)

This ensures that all native WordPress filters, statistical queries, and accountant views automatically use the correct date without complex logic.

## Implementation Details

### 1. Database Schema Changes

#### New Column: `activation_date`

Added to the `wp_cig_invoices` table:

```sql
ALTER TABLE `wp_cig_invoices` 
ADD COLUMN `activation_date` datetime DEFAULT NULL AFTER `updated_at`,
ADD KEY `activation_date` (`activation_date`);
```

**Properties:**
- Type: `datetime`
- Nullable: YES (allows NULL for fictive invoices and legacy data)
- Default: NULL
- Indexed: YES (for efficient date range queries)

### 2. Data Transfer Object (DTO) Updates

**File:** `includes/dto/class-cig-invoice-dto.php`

Added `activation_date` property:

```php
public $activation_date;
```

Updated methods:
- `from_array()` - Handles activation_date deserialization
- `from_postmeta()` - Reads from `_cig_activation_date` meta key
- `to_array()` - Includes activation_date in serialization

### 3. Business Logic Changes

**File:** `includes/services/class-cig-invoice-service.php`

#### Invoice Creation (`create_invoice`)

```php
// Track if invoice was created as Active (standard/proforma) from the beginning
// We use activation_date as a flag: NULL means either "created as Active" or "not yet activated"
// This distinction is important for the update logic
$activation_date = null;
```

**Behavior:**
- All invoices start with `activation_date` = NULL (whether created as Fictive or Active)
- Invoices created directly with payment (Active) retain their original `created_at` and `post_date`
- Invoices created without payment (Fictive) also retain their creation date until activated

#### Invoice Update (`update_invoice`)

```php
// Handle creation date update logic
$activation_date = $existing->activation_date; // Track if already activated
$created_at = $existing->created_at; // Default: preserve creation date
$update_post_date = false; // Flag to update WordPress post_date

// CORE LOGIC: Update creation date when transitioning from Fictive to Active (Sold/Reserved)
// Only if invoice hasn't been activated before (activation_date is NULL)
if ($existing->type === 'fictive' && $invoice_status === 'standard' && empty($existing->activation_date)) {
    $current_datetime = current_time('mysql');
    $created_at = $current_datetime; // Update creation date in custom table
    $activation_date = $current_datetime; // Mark as activated (prevents future updates)
    $update_post_date = true; // Also update WordPress post_date
}

// If reverting from standard to fictive, clear activation flag
// Note: We do NOT restore the original creation date as it may have been intentionally changed
if ($existing->type === 'standard' && $invoice_status === 'fictive') {
    $activation_date = null;
}

// Later in the code:
// Update WordPress post_date if invoice was activated
if ($update_post_date && $existing->old_post_id) {
    wp_update_post([
        'ID' => $existing->old_post_id,
        'post_date' => $created_at,
        'post_date_gmt' => get_gmt_from_date($created_at),
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', true),
    ]);
}
```

**Behavior:**
- **Preservation:** Existing dates are preserved on subsequent edits
- **Activation:** When Fictive → Standard transition occurs AND activation_date is NULL:
  - Updates `created_at` in custom table to current datetime
  - Updates `post_date` in WordPress to current datetime
  - Sets `activation_date` to current datetime (as a flag)
- **Reversion:** When Standard → Fictive transition occurs, clears `activation_date` flag
- **Idempotency:** Multiple saves of an activated invoice don't change its dates (activation_date is not NULL)
- **Direct Creation:** Invoices created directly as Active keep their original creation date (activation_date stays NULL)

#### Backward Compatibility (Postmeta)

```php
// Save activation_date to postmeta for backward compatibility
// Only set activation_date if it's provided (i.e., this is a Fictive→Standard transition)
if (!empty($activation_date)) {
    update_post_meta($post_id, '_cig_activation_date', $activation_date);
} elseif ($status === 'fictive') {
    // Clear activation_date when reverting to fictive status
    delete_post_meta($post_id, '_cig_activation_date');
}
// Note: Invoices created directly as Standard use created_at for statistics via COALESCE fallback in queries
```

**Behavior:**
- Only saves activation_date to postmeta when explicitly provided (Fictive→Standard transition)
- Clears activation_date when status becomes Fictive
- Does NOT set activation_date for invoices created directly as Standard

### 4. Repository Layer Changes (No Changes Required)

**File:** `includes/repositories/abstract-cig-repository.php`

The existing COALESCE-based date filtering continues to work as designed. However, with the new approach:

- **Transitioned invoices:** Use their updated `created_at` directly (no need for COALESCE)
- **Direct creation invoices:** Use their original `created_at` directly (no need for COALESCE)
- **Legacy invoices:** The COALESCE fallback still provides backward compatibility

The system naturally handles all cases because we're updating the primary timestamp rather than maintaining a separate field.

### 5. Statistics and Reporting

**Files:** 
- `includes/class-cig-statistics.php`
- `includes/ajax/class-cig-ajax-statistics.php`
- `includes/ajax/class-cig-ajax-dashboard.php`

**Key Benefit:** All native WordPress queries using `post_date` now automatically reflect the correct activation date for transitioned invoices. No special handling is required in most queries because the primary timestamp has been updated.

Existing activation_date handling (COALESCE logic) remains for backward compatibility but is no longer strictly necessary for new invoices.

## Usage Examples

### Example 1: Creating an Invoice with Payment (Direct Active)

```php
$data = [
    'invoice_number' => 'INV-001',
    'buyer' => ['name' => 'John Doe', ...],
    'items' => [...],
    'payment' => ['history' => [['amount' => 100, 'method' => 'cash']]],
];

$invoice_service->create_invoice($data);
// Result: 
// - created_at = 2024-01-10
// - post_date = 2024-01-10
// - activation_date = NULL (not set for direct creation)
// - Statistics will use created_at/post_date = 2024-01-10 ✅
```

### Example 2: Creating a Fictive Invoice

```php
$data = [
    'invoice_number' => 'INV-002',
    'buyer' => ['name' => 'Jane Smith', ...],
    'items' => [...],
    'payment' => ['history' => []], // No payment
];

$invoice_service->create_invoice($data);
// Result:
// - created_at = 2024-01-10 (draft creation date)
// - post_date = 2024-01-10
// - activation_date = NULL
```

### Example 3: Converting Fictive to Standard (THE KEY CHANGE)

```php
// Day 1 (Jan 10): Create fictive invoice
$invoice_id = $invoice_service->create_invoice($fictive_data);
// - created_at = 2024-01-10
// - post_date = 2024-01-10
// - activation_date = NULL

// Day 5 (Jan 14): Add payment and update (fictive → standard)
$data_with_payment = [..., 'payment' => ['history' => [['amount' => 100]]]];
$invoice_service->update_invoice($invoice_id, $data_with_payment);
// Result:
// - created_at = 2024-01-14 ✅ UPDATED!
// - post_date = 2024-01-14 ✅ UPDATED!
// - activation_date = 2024-01-14 (flag to prevent future updates)
// - Statistics will use created_at/post_date = 2024-01-14 ✅
```

### Example 4: Subsequent Edit (No Date Change)

```php
// Invoice was activated on Jan 14, now editing on Jan 20
$invoice_service->update_invoice($invoice_id, $updated_data);
// Result:
// - created_at = 2024-01-14 (unchanged, activation_date is set)
// - post_date = 2024-01-14 (unchanged)
// - activation_date = 2024-01-14 (unchanged)
// - Statistics still use 2024-01-14 ✅
```

### Example 5: Reverting Standard to Fictive

```php
// Invoice was active with dates = 2024-01-14
$data_no_payment = [..., 'payment' => ['history' => []]];
$invoice_service->update_invoice($invoice_id, $data_no_payment);
// Result:
// - created_at = 2024-01-14 (unchanged, date stays)
// - post_date = 2024-01-14 (unchanged)
// - activation_date = NULL (cleared, allowing future re-activation)
```

## Impact on Reporting

### Scenario: Invoice Created as Fictive, Then Activated

**Before This Change (Using activation_date field):**
```
Invoice INV-001:
- Created: 2024-01-10 (fictive draft)
- Payment Added: 2024-01-14 (became active)
- created_at: 2024-01-10
- post_date: 2024-01-10
- activation_date: 2024-01-14
- Problem: WordPress filters still use post_date (2024-01-10) ❌
- Workaround: Complex COALESCE queries needed
```

**After This Change (Updating primary timestamps):**
```
Invoice INV-001:
- Created: 2024-01-10 (fictive draft)
- Payment Added: 2024-01-14 (became active)
- created_at: 2024-01-14 ✅ UPDATED
- post_date: 2024-01-14 ✅ UPDATED
- activation_date: 2024-01-14 (flag only)
- Benefit: All WordPress filters automatically use post_date (2024-01-14) ✅
- No special queries needed!
```
```
Invoice INV-002:
- Created: 2024-01-20 (with payment, directly active)
- Activation Date: NULL (not set for direct creation)
- Reported Revenue Date: 2024-01-20 ✅ (correct - uses created_at via fallback)
```

**Key Difference:** The new implementation ensures:
1. **Transitioned invoices** (Fictive→Standard) use the transition date (activation_date)
2. **Direct invoices** (created as Standard) use their creation date (created_at)
3. **Fictive invoices** use their creation date (created_at) via fallback

## Migration Strategy

### For Existing Invoices

The system handles legacy data automatically:

1. **No Migration Required:** Existing invoices without `activation_date` will use `created_at` as fallback
2. **Gradual Update:** As invoices are edited and saved, `activation_date` will be populated if applicable
3. **Query Compatibility:** All queries use `COALESCE(activation_date, created_at)` for seamless fallback

### Database Schema Update

The schema migration runs automatically:

```php
// In CIG_Database::migrate_schema_to_current()
'activation_date' => [
    'column' => "ADD COLUMN `activation_date` datetime DEFAULT NULL AFTER `updated_at`",
    'index' => "ADD KEY `activation_date` (`activation_date`)",
],
```

## Testing

### Manual Testing Checklist

- [ ] Create new invoice with payment → verify activation_date is set immediately
- [ ] Create new invoice without payment → verify activation_date is NULL
- [ ] Update fictive invoice with payment → verify activation_date is set on update
- [ ] Update active invoice → verify activation_date is preserved
- [ ] Revert active invoice to fictive → verify activation_date is deleted
- [ ] Run statistics report → verify dates use activation_date with fallback
- [ ] Check accountant dashboard → verify sorting by activation_date
- [ ] Export statistics → verify exported dates use activation_date

### Automated Test Script

A test script is provided at `/tmp/test_activation_date.php` that can be run in a WordPress environment to verify:

1. Database schema has the activation_date column
2. DTO has the activation_date property
3. DTO correctly handles activation_date serialization
4. DTO correctly handles NULL activation_date

## Security Considerations

1. **No User Input:** activation_date is system-managed, not user-provided
2. **SQL Injection:** All queries use wpdb::prepare() with parameterized values
3. **Access Control:** Existing permission checks remain in place
4. **Data Integrity:** Activation date can only be set/cleared through business logic

## Performance Considerations

1. **Index Added:** `activation_date` column is indexed for efficient date range queries
2. **COALESCE Impact:** Minimal performance impact; MySQL optimizes COALESCE efficiently
3. **Backward Compatibility:** Postmeta fallback adds negligible overhead

## Future Enhancements

Potential improvements for future versions:

1. **Bulk Migration Tool:** One-time script to populate activation_date for existing active invoices
2. **Admin UI:** Display both "Created Date" and "Activation Date" in invoice list views
3. **Audit Trail:** Log activation date changes in a separate audit table
4. **API Endpoint:** Expose activation_date in REST API responses
5. **Report Widgets:** Add visual indicators showing draft vs. activated invoice counts

## Troubleshooting

### Issue: Statistics show old dates

**Cause:** Legacy invoices without activation_date are using created_at fallback

**Solution:** This is expected behavior. Run a one-time migration script to populate activation_date for existing active invoices, or wait for natural updates as invoices are edited.

### Issue: Activation date not being set

**Cause:** Invoice status is not transitioning to 'standard'

**Solution:** Verify that:
1. Payment history contains entries with amounts > 0
2. Payment amount calculation is correct
3. Invoice status is being set to 'standard' based on paid_amount

### Issue: Accountant dashboard shows wrong order

**Cause:** Some invoices might not have activation_date set

**Solution:** The system automatically falls back to created_at for invoices without activation_date. This is expected behavior.

## References

- Original issue: "Migration Database Errors and Activation Date Logic"
- Database schema: `includes/database/class-cig-database.php`
- Business logic: `includes/services/class-cig-invoice-service.php`
- Repository layer: `includes/repositories/abstract-cig-repository.php`
- Statistics engine: `includes/class-cig-statistics.php`

## Changelog

### v5.0.1 (2024-01-14)

- Added `activation_date` column to database schema
- Updated DTO to include activation_date property
- Implemented activation_date logic in invoice service
- Updated repository queries to use activation_date with fallback
- Updated statistics engine to use activation_date
- Updated accountant dashboard to sort by activation_date
- Added backward compatibility for postmeta storage
- Added comprehensive documentation

---

**Last Updated:** 2024-01-14  
**Version:** 5.0.1  
**Author:** GitHub Copilot (with Samsiani)
