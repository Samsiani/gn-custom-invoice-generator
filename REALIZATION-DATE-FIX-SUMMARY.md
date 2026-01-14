# Realization-Based Statistical Reporting - Implementation Summary

## Overview
This implementation fixes the activation_date logic to correctly distinguish between:
1. **Invoices created directly as Active** → use creation date for statistics
2. **Invoices that transition from Fictive to Active** → use transition date for statistics

## Problem Statement
The system needed to distinguish between Document Creation Date and Sales Realization Date. Previously, all invoices with payments (Standard status) were getting an activation_date set at creation time, which prevented distinguishing between:
- Invoices created directly with payment (should use creation date)
- Invoices created as drafts then later finalized (should use finalization date)

## Solution
Modified the invoice service to **ONLY** set `activation_date` when a Fictive invoice transitions to Standard status. Invoices created directly as Standard do NOT get an activation_date, and instead rely on the `COALESCE(activation_date, created_at)` fallback in statistics queries.

## Key Changes

### 1. Invoice Creation (class-cig-invoice-service.php, lines 83-86)
**Before:**
```php
$activation_date = null;
if ($invoice_status === 'standard') {
    $activation_date = current_time('mysql'); // ❌ Wrong
}
```

**After:**
```php
// Do NOT set activation_date for invoices created directly as Standard
// activation_date should ONLY be set when a Fictive invoice transitions to Standard
// Invoices "born" active should be categorized by their created_at date
$activation_date = null; // ✅ Correct
```

### 2. Invoice Update (class-cig-invoice-service.php, lines 205-215)
**No changes needed** - Already correctly implemented:
```php
$activation_date = $existing->activation_date; // Preserve by default

// Set on transition
if ($existing->type === 'fictive' && $invoice_status === 'standard' && empty($existing->activation_date)) {
    $activation_date = current_time('mysql');
}

// Clear on reversion
if ($existing->type === 'standard' && $invoice_status === 'fictive') {
    $activation_date = null;
}
```

### 3. Postmeta Sync (class-cig-invoice-service.php, lines 437-461)
**Modified to accept activation_date parameter:**
```php
private function save_to_postmeta($post_id, $data, $status, $activation_date = null) {
    // ... other postmeta updates ...
    
    // Only set activation_date if it's provided (transition case)
    if (!empty($activation_date)) {
        update_post_meta($post_id, '_cig_activation_date', $activation_date);
    } elseif ($status === 'fictive') {
        delete_post_meta($post_id, '_cig_activation_date');
    }
    // Direct creation as Standard: activation_date stays NULL
}
```

## How It Works

### Statistics Query Strategy
All statistics use `COALESCE(activation_date, created_at)` which means:
- If `activation_date` is set (transitioned invoice) → use it
- If `activation_date` is NULL (direct creation or fictive) → use `created_at`

This is implemented in:
- `abstract-cig-repository.php` (lines 162, 167) - Repository layer
- `class-cig-ajax-statistics.php` (lines 100-101) - AJAX handlers
- `class-cig-ajax-dashboard.php` (lines 298-299) - Accountant dashboard
- `class-cig-statistics.php` (lines 274-275) - Export functions

## Examples

### Example 1: Direct Creation with Payment
```
Date: January 10
Action: Create invoice with payment
Status: Standard
Result:
  - created_at: 2024-01-10
  - activation_date: NULL
  - Statistics use: 2024-01-10 (created_at)
  - Appears in: January 10 date filter ✅
```

### Example 2: Fictive to Standard Transition
```
Date: January 10
Action: Create invoice without payment
Status: Fictive
Result:
  - created_at: 2024-01-10
  - activation_date: NULL
  
Date: January 14
Action: Add payment
Status: Standard (transition)
Result:
  - created_at: 2024-01-10 (unchanged)
  - activation_date: 2024-01-14 (set on transition)
  - Statistics use: 2024-01-14 (activation_date)
  - Appears in: January 14 date filter ✅
  - Does NOT appear in: January 10-13 date filter ✅
```

### Example 3: Standard to Fictive Reversion
```
Date: January 14
Action: Remove payment from active invoice
Status: Fictive (reversion)
Result:
  - created_at: 2024-01-10 (unchanged)
  - activation_date: NULL (cleared)
  - Statistics use: 2024-01-10 (created_at via fallback)
```

## Requirements Compliance

### Requirement 1: Activation-Date Logic (Fictive to Active Transition) ✅
- Invoices transitioning from Fictive to Standard get `activation_date` set to transition time
- This date is used for statistics and reporting

### Requirement 2: Filtering and Visibility ✅
- Date filters use `COALESCE(activation_date, created_at)` in all queries
- Transitioned invoices appear in date ranges based on their activation_date
- Direct invoices appear in date ranges based on their created_at

### Requirement 3: Direct Creation Logic ✅
- Invoices created directly as Active do NOT get activation_date set
- These invoices use their created_at date for statistics
- Clear distinction between "born active" and "transitioned to active"

### Requirement 4: Objective ✅
- Analytics accurately reflect when sales were actually finalized
- Revenue is attributed to the correct financial period
- Accountants see invoices sorted by realization date, not draft creation date

## Testing

### Validation Script
Created `/tmp/test_activation_date_logic.php` that validates:
- ✅ Direct creation as Standard: activation_date = NULL
- ✅ Fictive → Standard transition: activation_date = transition time
- ✅ Standard → Fictive reversion: activation_date = NULL
- ✅ Statistics use COALESCE fallback correctly
- ✅ Postmeta only syncs when activation_date is explicitly provided

All tests passed successfully.

### Code Quality
- ✅ PHP syntax validation passed
- ✅ Code review completed with all feedback addressed
- ✅ CodeQL security scan passed (no issues found)
- ✅ Documentation updated and verified

## Files Modified
1. `includes/services/class-cig-invoice-service.php` - Core business logic
2. `ACTIVATION-DATE-IMPLEMENTATION.md` - Documentation updates

## Backward Compatibility
- ✅ Existing invoices with activation_date set remain unchanged
- ✅ Existing invoices without activation_date use created_at via fallback
- ✅ All queries use COALESCE for seamless backward compatibility
- ✅ Postmeta storage maintained for legacy support

## Performance
- No performance impact - queries already use COALESCE
- No additional database queries needed
- Batch fetching of activation dates already implemented

## Migration Notes
No migration needed:
- Existing invoices continue to work correctly
- New invoices will follow the corrected logic
- System automatically handles both old and new data

## Conclusion
The implementation now correctly satisfies all requirements from the technical specification. The system properly distinguishes between:
1. Invoices created directly as active (use creation date)
2. Invoices that transition from fictive to active (use activation/realization date)

This ensures accurate financial reporting with revenue attributed to the correct periods.

---
**Implementation Date:** January 14, 2026
**Status:** ✅ Complete and Production Ready
**All Requirements:** ✅ Satisfied
