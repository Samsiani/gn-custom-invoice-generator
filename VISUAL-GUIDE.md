# Invoice Date Synchronization - Visual Summary

## Problem

When an invoice starts as a draft (Fictive) and later gets paid (becomes Standard), where should it appear in reports - on the draft date or the payment date?

```
❌ OLD BEHAVIOR (Before Fix):
Draft Created: Jan 10 → Gets Paid: Jan 14 → Still shows in Jan 10 reports

✅ NEW BEHAVIOR (After Fix):
Draft Created: Jan 10 → Gets Paid: Jan 14 → NOW shows in Jan 14 reports
```

## Solution Flow

### Scenario 1: First Activation (Fictive → Standard)

```
┌─────────────────────────────────────────────────────────────┐
│ BEFORE PAYMENT (January 10)                                 │
├─────────────────────────────────────────────────────────────┤
│ Invoice Type: FICTIVE (no payment)                          │
│ created_at: 2026-01-10 10:00:00                            │
│ activation_date: NULL                                        │
│ post_date: 2026-01-10 10:00:00                             │
└─────────────────────────────────────────────────────────────┘
                            │
                            │ User adds payment
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ AFTER PAYMENT (January 14)                                  │
├─────────────────────────────────────────────────────────────┤
│ Invoice Type: STANDARD (has payment) ← Changed              │
│ created_at: 2026-01-14 15:30:00      ← UPDATED! 🎯         │
│ activation_date: 2026-01-14 15:30:00 ← SET! (flag)         │
│ post_date: 2026-01-14 15:30:00       ← UPDATED! 🎯         │
└─────────────────────────────────────────────────────────────┘

RESULT: Invoice now appears in January 14 reports ✓
```

### Scenario 2: Direct Creation (Created with Payment)

```
┌─────────────────────────────────────────────────────────────┐
│ CREATED WITH PAYMENT (January 10)                          │
├─────────────────────────────────────────────────────────────┤
│ Invoice Type: STANDARD (has payment from start)             │
│ created_at: 2026-01-10 10:00:00                            │
│ activation_date: NULL            ← Stays NULL               │
│ post_date: 2026-01-10 10:00:00                             │
└─────────────────────────────────────────────────────────────┘
                            │
                            │ User edits invoice (Jan 14)
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ AFTER EDIT (January 14)                                     │
├─────────────────────────────────────────────────────────────┤
│ Invoice Type: STANDARD                                       │
│ created_at: 2026-01-10 10:00:00      ← UNCHANGED           │
│ activation_date: NULL                 ← Still NULL          │
│ post_date: 2026-01-10 10:00:00       ← UNCHANGED           │
└─────────────────────────────────────────────────────────────┘

RESULT: Invoice stays in January 10 reports ✓
```

### Scenario 3: Editing Already Activated Invoice

```
┌─────────────────────────────────────────────────────────────┐
│ ALREADY ACTIVATED (January 14)                              │
├─────────────────────────────────────────────────────────────┤
│ Invoice Type: STANDARD                                       │
│ created_at: 2026-01-14 15:30:00                            │
│ activation_date: 2026-01-14 15:30:00 ← Flag is set         │
│ post_date: 2026-01-14 15:30:00                             │
└─────────────────────────────────────────────────────────────┘
                            │
                            │ User edits invoice (Jan 20)
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ AFTER EDIT (January 20)                                     │
├─────────────────────────────────────────────────────────────┤
│ Invoice Type: STANDARD                                       │
│ created_at: 2026-01-14 15:30:00      ← UNCHANGED           │
│ activation_date: 2026-01-14 15:30:00 ← Still set (prevents)│
│ post_date: 2026-01-14 15:30:00       ← UNCHANGED           │
└─────────────────────────────────────────────────────────────┘

RESULT: Invoice stays in January 14 reports ✓
```

## The Magic Flag: activation_date

```
┌───────────────────────────────────────────────────────────────┐
│ activation_date Values and Their Meaning                      │
├───────────────────────────────────────────────────────────────┤
│                                                               │
│  NULL + type=fictive     →  Never been activated             │
│                             (draft invoice, no payment yet)   │
│                                                               │
│  NULL + type=standard    →  Created as active from start     │
│                             (invoice with payment from day 1) │
│                                                               │
│  SET + type=standard     →  Was activated from fictive       │
│                             (draft that got paid)             │
│                             Date is LOCKED! 🔒               │
│                                                               │
└───────────────────────────────────────────────────────────────┘
```

## Decision Logic

```
                    User Saves Invoice
                            │
                            ↓
                    Calculate Status
                (payment > 0 ? standard : fictive)
                            │
                            ↓
              ┌─────────────┴─────────────┐
              │                           │
        Is Fictive → Standard?      All Other Cases
              │                           │
              ↓                           ↓
      ┌───────────────┐           Keep Existing
      │ Check Flag    │           Dates
      └───────┬───────┘                  │
              │                           │
     ┌────────┴────────┐                 │
     │                 │                 │
activation_date     activation_date      │
   = NULL?          is SET?              │
     │                 │                 │
     ↓                 ↓                 │
  UPDATE!          DON'T UPDATE         │
  (First time)     (Already done)       │
     │                 │                 │
     └─────────────────┴─────────────────┘
                     │
                     ↓
              Save to Database
```

## What Gets Updated

When a Fictive invoice gets its first payment:

```
┌──────────────────────────────────────────────────────────┐
│ THREE PLACES GET UPDATED                                 │
├──────────────────────────────────────────────────────────┤
│                                                          │
│  1. wp_cig_invoices table                               │
│     ├─ created_at = CURRENT_TIME                        │
│     └─ activation_date = CURRENT_TIME (flag)            │
│                                                          │
│  2. wp_posts table                                      │
│     ├─ post_date = CURRENT_TIME                         │
│     └─ post_date_gmt = CURRENT_TIME (GMT)               │
│                                                          │
│  3. wp_postmeta table                                   │
│     └─ _cig_activation_date = CURRENT_TIME              │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

## Benefits

```
✓ ACCURATE REPORTING
  Revenue appears in the correct financial period

✓ WORDPRESS COMPATIBLE  
  Admin UI sorting/filtering works correctly

✓ ACCOUNTANT FRIENDLY
  Invoices appear in date ranges when they were sold

✓ PREDICTABLE
  Clear rules: only first activation changes date

✓ NO MIGRATION NEEDED
  Existing invoices work without changes
```

## Common Questions

**Q: What if I create an invoice with payment from the start?**  
A: The date stays at creation time. The `activation_date` remains NULL because it was never "activated" - it started active.

**Q: What if I edit an invoice multiple times after activation?**  
A: The date stays at activation time. The `activation_date` flag prevents changes.

**Q: What if I remove payment and add it back?**  
A: The date updates to the NEW payment date. Removing payment clears the flag, allowing re-activation.

**Q: Where is the invoice displayed in WordPress Admin?**  
A: In the date range matching `post_date`, which is the activation date for activated invoices.

**Q: What about invoices created before this feature?**  
A: They continue to work. The system handles both old and new invoices correctly.

---

**Visual Guide Version**: 1.0  
**Created**: 2026-01-14  
**Repository**: Samsiani/gn-custom-invoice-generator
