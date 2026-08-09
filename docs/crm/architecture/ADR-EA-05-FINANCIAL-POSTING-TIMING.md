# ADR EA-05 — Financial Posting Timing

## Status

**[x] Approved — 2026-08-09**

## Approved Decision

Laravel is the sole authoritative financial system for Invoice, Payment, Customer AR, accounting entries, Settlement Clearance, refund, void, reversal, and credit adjustment. Cloud is not a financial ledger.

Website Order approval creates a Laravel Operational Order, but **Order Approval ≠ Official Invoice**. An Official Invoice is created or ensured when the first authoritative Financial Commitment occurs: either Verified Payment or authorized Customer Account/Credit settlement.

## Payment and Settlement

Payment Proof Uploaded is not Payment. Laravel review and a committed/recoverable financial transaction create the authoritative Payment and applicable posting. Unknown outcomes reconcile before any replay.

Partial Payment is valid but does not necessarily satisfy Settlement Clearance. For an Invoice of 100 and verified Payment of 40, 40 is valid and the Invoice is partially settled; a further verified 60 may allow clearance subject to business rules.

Authorized Account/Credit settlement may create/ensure the Invoice, recognize Customer Receivable/AR, and satisfy Settlement Clearance after customer eligibility, permission, credit/business rules, authorized actor, and audit validation. It never pretends Cash Payment occurred.

**Settlement Clearance** is authoritative financial permission for production eligibility. It may be satisfied by fully verified required Payment or authorized Account/Credit settlement. Payment Verified does not necessarily mean cleared, and Settlement Clearance never means Kitchen Release.

```text
Settlement Cleared + Order production eligibility
→ explicit Kitchen Release
```

## Cancellation, Ambiguity, and Overpayment

Cancellation never erases committed financial truth. Refund, void, reversal, credit adjustment, or receivable adjustment records the actual governed disposition. Unknown financial outcomes reconcile and recover durable results before replay; unresolved ambiguity enters Needs Finance Review.

For MVP, verified overpayment is rejected or routed to Finance Review. It does not silently create wallet balance, customer credit, automatic refund, or another unapproved financial feature.

## Architecture Review Decisions

1. **Approved:** Laravel is the sole financial authority; Cloud is not a ledger.
2. **Approved:** Order Approval is not Invoice creation.
3. **Approved:** the first authoritative Financial Commitment creates or ensures the Official Invoice.
4. **Approved:** proof upload is not Payment; only Laravel verification/transaction establishes financial fact.
5. **Approved:** partial Payments are valid but do not necessarily satisfy Settlement Clearance.
6. **Approved:** authorized Account/Credit settlement may recognize AR and satisfy clearance without pretending Payment occurred.
7. **Approved:** Settlement Clearance is separate from Payment Verification and explicit Kitchen Release.
8. **Approved:** cancellation preserves financial history through governed disposition.
9. **Approved:** unknown effects reconcile before replay and unresolved ambiguity enters Finance Review.
10. **Approved:** MVP overpayment is rejected or reviewed, never silently converted into an unapproved balance/refund.

## Phase F Implications

Phase F defines journal posting, Invoice transaction boundaries, AR posting, refund/void/reversal mechanics, Payment allocation, credit-limit implementation, Laravel service integration, and database constraints. No implementation is approved or performed here.
