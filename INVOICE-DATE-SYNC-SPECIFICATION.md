# Invoice Creation Date Synchronization - Technical Specification

## Overview

This document describes the implementation of automatic timestamp synchronization when invoices transition from Fictive to Active status.

## Problem Statement

When an invoice is created as "Fictive" (draft without payment) and later receives payment to become "Active" (Standard/Sold), the system must update the primary document timestamp to reflect the realization date rather than the draft date.

### Why This Matters

1. **Financial Reporting**: Revenue should be attributed to the period when the sale was finalized, not when the draft was created
2. **WordPress Compatibility**: Native WordPress filters and dashboards rely on `post_date` for sorting and filtering
3. **Accountant Requirements**: Invoices must appear in the correct date ranges for tax and compliance purposes

## Implementation Details

### Core Logic Location

**File**: `includes/services/class-cig-invoice-service.php`  
**Method**: `update_invoice()`  
**Key Sections**: 
- Transition detection and timestamp update logic
- WordPress post_date synchronization via `wp_update_post()`
- Postmeta backward compatibility via `save_to_postmeta()`

### Business Rules

#### Rule 1: First Activation Triggers Date Update
```php
if ($existing->type === 'fictive' && $invoice_status === 'standard' && empty($existing->activation_date))
```
- **Condition**: Invoice transitions from Fictive to Standard (has payment)
- **Check**: `activation_date` is NULL (not yet activated)
- **Action**: Update timestamps to current time

#### Rule 2: Direct Creation Preserves Original Date
- Invoices created with payment from the start have `activation_date = NULL`
- The update logic checks `existing->type === 'fictive'` which is FALSE
- Original `created_at` is preserved

#### Rule 3: Subsequent Edits Don't Move Date
- After first activation, `activation_date` is set (not NULL)
- The check `empty($existing->activation_date)` returns FALSE
- Dates remain unchanged on subsequent edits

#### Rule 4: Reversion Clears Activation Flag
```php
if ($existing->type === 'standard' && $invoice_status === 'fictive')
```
- When payment is removed, `activation_date` is set to NULL
- Allows future re-activation to set new timestamp
- Original `created_at` is NOT restored

### What Gets Updated

When an invoice is activated (Fictive → Standard, first time):

1. **Custom Table** (`wp_cig_invoices`):
   - `created_at` ← current timestamp
   - `activation_date` ← current timestamp (as flag)

2. **WordPress Core** (`wp_posts`):
   - `post_date` ← current timestamp
   - `post_date_gmt` ← current timestamp (GMT)
   - `post_modified` ← current timestamp
   - `post_modified_gmt` ← current timestamp (GMT)

3. **Postmeta** (backward compatibility):
   - `_cig_activation_date` ← current timestamp

### Code Flow

```
update_invoice() called
    ↓
Calculate new invoice_status based on payments
    ↓
Check: Is this Fictive → Standard transition?
    ↓
Yes: existing->type === 'fictive' 
     AND invoice_status === 'standard'
     AND empty(existing->activation_date)
    ↓
Generate new timestamp: current_time('mysql')
    ↓
Update variables:
    - created_at = new timestamp
    - activation_date = new timestamp
    - update_post_date = true
    ↓
Create DTO with updated created_at and activation_date
    ↓
Call invoice_repo->update() to persist to database
    ↓
If update_post_date: call wp_update_post()
    ↓
Call save_to_postmeta() to sync backward compatibility
```

### Database Schema

The implementation relies on these fields:

**wp_cig_invoices table**:
- `created_at` (DATETIME) - Primary creation/realization timestamp
- `activation_date` (DATETIME NULL) - Flag indicating if invoice was activated from Fictive
- `type` (VARCHAR) - Invoice type: 'fictive', 'standard', 'proforma'

**wp_posts table**:
- `post_date` (DATETIME) - WordPress creation date (synced with `created_at`)
- `post_date_gmt` (DATETIME) - GMT version of post_date

**wp_postmeta**:
- `_cig_activation_date` - Legacy field for backward compatibility

## Test Scenarios

### Scenario 1: First Activation
```
1. Create invoice as Fictive on Jan 10
   - created_at: 2026-01-10
   - activation_date: NULL
   
2. Add payment on Jan 14 (invoice becomes Standard)
   - created_at: 2026-01-14 ← UPDATED
   - activation_date: 2026-01-14 ← SET
   - post_date: 2026-01-14 ← UPDATED

Result: Invoice appears in Jan 14 reports ✓
```

### Scenario 2: Direct Creation as Standard
```
1. Create invoice with payment on Jan 10
   - created_at: 2026-01-10
   - activation_date: NULL
   - type: 'standard'

2. Edit invoice on Jan 14
   - created_at: 2026-01-10 ← UNCHANGED
   - activation_date: NULL ← UNCHANGED
   - post_date: 2026-01-10 ← UNCHANGED

Result: Invoice stays in Jan 10 reports ✓
```

### Scenario 3: Subsequent Edit After Activation
```
1. Invoice activated on Jan 14
   - created_at: 2026-01-14
   - activation_date: 2026-01-14

2. Edit invoice on Jan 20
   - created_at: 2026-01-14 ← UNCHANGED
   - activation_date: 2026-01-14 ← UNCHANGED
   - post_date: 2026-01-14 ← UNCHANGED

Result: Invoice stays in Jan 14 reports ✓
```

### Scenario 4: Reversion and Re-activation
```
1. Invoice activated on Jan 14
   - created_at: 2026-01-14
   - activation_date: 2026-01-14

2. Remove payment on Jan 18 (becomes Fictive)
   - created_at: 2026-01-14 ← UNCHANGED
   - activation_date: NULL ← CLEARED
   - post_date: 2026-01-14 ← UNCHANGED

3. Add payment again on Jan 20 (becomes Standard)
   - created_at: 2026-01-20 ← UPDATED
   - activation_date: 2026-01-20 ← SET
   - post_date: 2026-01-20 ← UPDATED

Result: Invoice moves to Jan 20 reports ✓
```

## Benefits

1. **Simplified Queries**: No need for complex COALESCE logic in most queries
2. **WordPress Compatible**: Native WP functions work correctly out of the box
3. **Accurate Reporting**: Revenue appears in correct financial period
4. **Predictable Behavior**: Clear rules for when dates change
5. **Backward Compatible**: Legacy systems using postmeta continue to work

## Migration Notes

### No Migration Required

Existing invoices continue to work without database migration:

- **Already Activated**: Have `activation_date` set, won't be re-activated
- **Created as Standard**: Have `activation_date = NULL` and `type = 'standard'`, dates preserved
- **Pending Fictive**: Will use new logic on first activation

### Compatibility

- Custom table queries use `created_at` directly
- WordPress Admin uses `post_date` automatically
- Legacy reports can use `COALESCE(activation_date, created_at)` if needed
- Postmeta `_cig_activation_date` maintained for old code

## Security Considerations

1. **Permission Check**: Only administrators can edit completed invoices
2. **Data Integrity**: `activation_date` acts as an immutable flag after first set
3. **Audit Trail**: `post_modified` tracks when changes were made
4. **Validation**: DTO validation ensures data consistency

## Performance Impact

- **Minimal**: One additional database write per activation (wp_update_post)
- **Optimized**: Update only happens once per invoice lifetime
- **Cached**: Repository uses caching to minimize database hits

## Code Quality

- ✅ PHP syntax validated
- ✅ Logic validated with comprehensive test script
- ✅ All edge cases handled
- ✅ Backward compatible
- ✅ Well documented

## Related Files

1. `includes/services/class-cig-invoice-service.php` - Main business logic
2. `includes/dto/class-cig-invoice-dto.php` - Data transfer object
3. `includes/repositories/class-cig-invoice-repository.php` - Database persistence
4. `CREATION-DATE-UPDATE-SUMMARY.md` - Implementation summary
5. `ACTIVATION-DATE-IMPLEMENTATION.md` - Detailed activation logic

## Maintenance

### When to Review This Code

- Adding new invoice status types
- Modifying payment processing logic
- Changing date handling in reports
- Implementing invoice lifecycle changes

### Common Issues and Solutions

**Issue**: Invoice appears in wrong date range  
**Check**: 
- Verify `activation_date` is set correctly
- Check `post_date` in wp_posts
- Confirm query uses correct date field

**Issue**: Date changes unexpectedly  
**Check**:
- Verify `activation_date` was NULL before change
- Check invoice type transition (Fictive → Standard)
- Review recent payment history changes

**Issue**: Direct creation date incorrect  
**Check**:
- Verify `activation_date` is NULL for direct creations
- Confirm `created_at` matches `post_date`
- Check that type is 'standard' from creation

## Version History

- **Current Version**: Implementation complete and tested
- **Spec Date**: 2026-01-14
- **Status**: Complete and Tested

---

For questions or issues, refer to the problem statement and the comprehensive test scenarios documented in this specification.
