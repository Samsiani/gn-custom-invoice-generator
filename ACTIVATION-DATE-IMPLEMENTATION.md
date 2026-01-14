# Activation Date Implementation

## Overview

This document describes the implementation of the "Activation Date" logic for accurate financial reporting in the Custom Invoice Generator system. The system now tracks when an invoice transitions from "Fictive" (draft) status to "Standard" (active) status, ensuring that statistics and reports reflect actual sales realization dates rather than creation dates.

## Problem Statement

Previously, the system used the invoice creation date (`created_at`) for all statistical reporting. This was misleading because:

1. Many invoices are created as "Fictive" drafts long before they are actually finalized
2. Financial reports showed revenue on the draft creation date, not the actual sale date
3. Monthly performance metrics were inaccurate due to this timing mismatch
4. Accountants needed to see invoices sorted by realization date, not draft creation date

## Solution

The system now captures an "Activation Date" (`activation_date`) that represents when an invoice becomes active (transitions to "Standard" status). This date is used as the primary filter for all statistical queries, with automatic fallback to `created_at` for legacy data.

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
// Do NOT set activation_date for invoices created directly as Standard
// activation_date should ONLY be set when a Fictive invoice transitions to Standard
// Invoices "born" active should be categorized by their created_at date
$activation_date = null;
```

**Behavior:**
- If an invoice is created with payment (Standard) → `activation_date` remains NULL (uses `created_at` for statistics)
- If an invoice is created without payment (Fictive) → `activation_date` remains NULL
- **Key Point:** Direct creation does NOT set activation_date, ensuring invoices are categorized by their creation date

#### Invoice Update (`update_invoice`)

```php
// Handle activation_date logic
$activation_date = $existing->activation_date; // Preserve existing by default

// If transitioning from fictive to standard, set activation_date
if ($existing->type === 'fictive' && $invoice_status === 'standard' && empty($existing->activation_date)) {
    $activation_date = current_time('mysql');
}

// If reverting from standard to fictive, clear activation_date
if ($existing->type === 'standard' && $invoice_status === 'fictive') {
    $activation_date = null;
}
```

**Behavior:**
- **Preservation:** Existing `activation_date` is preserved on subsequent edits
- **Activation:** When Fictive → Standard transition occurs AND activation_date is NULL, set `activation_date` to current time
- **Reversion:** When Standard → Fictive transition occurs, clear `activation_date`
- **Idempotency:** Multiple saves of an active invoice don't change its `activation_date`
- **Direct Creation:** Invoices created directly as Standard will NOT have activation_date set (it remains NULL)

#### Backward Compatibility (Postmeta)

```php
// Save activation_date to postmeta for backward compatibility
// Only set activation_date if it's provided (i.e., this is a Fictive→Standard transition)
if (!empty($activation_date)) {
    update_post_meta($post_id, '_cig_activation_date', $activation_date);
} else if ($status === 'fictive') {
    // If status is fictive, clear any existing activation_date
    delete_post_meta($post_id, '_cig_activation_date');
}
// Note: For invoices created directly as Standard, we don't set activation_date
```

**Behavior:**
- Only saves activation_date to postmeta when explicitly provided (Fictive→Standard transition)
- Clears activation_date when status becomes Fictive
- Does NOT set activation_date for invoices created directly as Standard

### 4. Repository Layer Changes

**File:** `includes/repositories/abstract-cig-repository.php`

#### Date Range Filtering with Fallback

```php
// Handle date range filtering with activation_date fallback to created_at
if (isset($filters['date_from']) || isset($filters['date_to'])) {
    $date_conditions = [];
    
    if (isset($filters['date_from'])) {
        $date_conditions[] = "COALESCE(`activation_date`, `created_at`) >= %s";
        $values[] = $filters['date_from'];
    }
    
    if (isset($filters['date_to'])) {
        $date_conditions[] = "COALESCE(`activation_date`, `created_at`) <= %s";
        $values[] = $filters['date_to'];
    }
    
    if (!empty($date_conditions)) {
        $where[] = '(' . implode(' AND ', $date_conditions) . ')';
    }
}
```

**Key Points:**
- Uses `COALESCE(activation_date, created_at)` for SQL queries
- Ensures legacy invoices (without activation_date) fall back to created_at
- Maintains backward compatibility with existing data

#### Date-Based Ordering

```php
// Use activation_date with fallback to created_at for default ordering
if ($order_by === 'created_at' || $order_by === 'activation_date') {
    return "ORDER BY COALESCE(`activation_date`, `created_at`) {$order}";
}
```

### 5. Statistics Engine Updates

**File:** `includes/class-cig-statistics.php`

#### Export Filtering

Updated `generate_excel_export()` to use activation_date:

```php
// Use activation_date with fallback to post_date
$activation_date = get_post_meta($invoice_id, '_cig_activation_date', true);
$invoice_date = $activation_date ?: get_post_field('post_date', $invoice_id);
```

#### Date Query with Activation Date

```php
$args['meta_query'] = [
    'relation' => 'OR',
    [
        'key'     => '_cig_activation_date',
        'value'   => [$date_from . ' 00:00:00', $date_to . ' 23:59:59'],
        'compare' => 'BETWEEN',
        'type'    => 'DATETIME'
    ],
    [
        'relation' => 'AND',
        ['key' => '_cig_activation_date', 'compare' => 'NOT EXISTS'],
    ]
];

$args['date_query'] = [['after' => $date_from.' 00:00:00', 'before' => $date_to.' 23:59:59', 'inclusive' => true]];
```

### 6. AJAX Handler Updates

**Files:** 
- `includes/ajax/class-cig-ajax-statistics.php`
- `includes/ajax/class-cig-ajax-dashboard.php`

#### Statistics Summary

All date-based queries now use activation_date with fallback:

```php
// Use activation_date with fallback to post_date
$activation_date = get_post_meta($id, '_cig_activation_date', true);
$d = $activation_date ?: get_post_field('post_date', $id);
```

#### Accountant Dashboard Sorting

Updated to sort by activation_date (descending):

```php
'orderby'        => 'meta_value',
'meta_key'       => '_cig_activation_date',
'order'          => 'DESC',
```

This ensures accountants see the most recently finalized transactions at the top, regardless of when the initial draft was created.

## Usage Examples

### Example 1: Creating an Invoice with Payment

```php
$data = [
    'invoice_number' => 'INV-001',
    'buyer' => ['name' => 'John Doe', ...],
    'items' => [...],
    'payment' => ['history' => [['amount' => 100, 'method' => 'cash']]],
];

$invoice_service->create_invoice($data);
// Result: activation_date = NULL (invoice created directly as Standard)
// Statistics will use created_at for this invoice
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
// Result: activation_date = NULL (remains NULL)
```

### Example 3: Converting Fictive to Standard

```php
// Day 1: Create fictive invoice
$invoice_id = $invoice_service->create_invoice($fictive_data);

// Day 5: Add payment and update (fictive → standard)
$data_with_payment = [..., 'payment' => ['history' => [['amount' => 100]]]];
$invoice_service->update_invoice($invoice_id, $data_with_payment);
// Result: activation_date = current_time() on Day 5
```

### Example 4: Reverting Standard to Fictive

```php
// Invoice was active with activation_date = '2024-01-15'
$data_no_payment = [..., 'payment' => ['history' => []]];
$invoice_service->update_invoice($invoice_id, $data_no_payment);
// Result: activation_date = NULL (cleared)
```

## Impact on Reporting

### Scenario 1: Invoice Created as Fictive, Then Activated

**Before Implementation:**
```
Invoice INV-001:
- Created: 2024-01-10 (fictive draft)
- Payment Added: 2024-01-15 (became active)
- Reported Revenue Date: 2024-01-10 ❌ (incorrect - used creation date)
```

**After Implementation:**
```
Invoice INV-001:
- Created: 2024-01-10 (fictive draft)
- Activation Date: 2024-01-15 (when payment added)
- Reported Revenue Date: 2024-01-15 ✅ (correct - uses activation_date)
```

### Scenario 2: Invoice Created Directly as Active

**Before Implementation:**
```
Invoice INV-002:
- Created: 2024-01-20 (with payment, directly active)
- Reported Revenue Date: 2024-01-20 ✅ (correct, but by accident)
```

**After Implementation:**
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
