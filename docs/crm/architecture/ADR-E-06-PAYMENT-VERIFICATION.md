# ADR E-06 — Payment Verification

## Status

**[R] Proposed — Ready for Architecture Review**

This ADR proposes authority, evidence, verification, clearance, failure, cancellation, and production-boundary semantics only. It does not approve schemas, names of fields or enums, APIs, storage products, queues/jobs, UI, notifications, financial posting timing, refunds, or Phase F application work.

## Context

E-05 creates a Laravel `Order` at authorized website-order approval but deliberately separates Order approval, payment verification, and production release. E-06 must decide where customer-supplied Payment Proof lives, who may establish payment truth, what a verified payment means, and how uncertain or repeated operations remain safe under E-02 at-least-once synchronization.

The central recommendation is:

```text
Cloud = private evidence custody
Laravel = payment and financial truth
```

## Approved E-01 through E-05 Constraints

- E-01 approves an independent Cloud Integration Service, outbound-only Local Sync Agent, Laravel operational/financial authority, and private Cloud object storage for private evidence.
- E-02 approves hybrid durable synchronization, durable acknowledgement, reconciliation, and at-least-once transfer without an exactly-once transport claim.
- E-03 establishes that Payment Proof is a separate evidence purpose/workflow, not a normal Message Attachment.
- E-04 establishes that `Payment Proof Uploaded ≠ Payment Verified`; customer payment facts may be communicated only after the authority establishes them.
- E-05 establishes Cloud-staged website intent followed by authorized creation of a Laravel Order, and keeps Order approval, payment verification, and production release distinct.
- E-07 through E-10 and EA-01 through EA-06 remain pending. Phase F remains not started.

## Business Constraints

1. Proof existence, filename, OCR output, customer-entered amount/reference, image contents, or elapsed time cannot establish payment truth.
2. A proof receipt may be acknowledged immediately, but a payment-confirmed statement requires a committed, recoverable Laravel result.
3. Verification requires a separately authorized actor and audit; ordinary Order edit/approval authority is insufficient by itself.
4. Routine verification may be performed by a specifically authorized operations employee. Ambiguous, conflicting, duplicated, or financially indeterminate cases belong in Finance Review.
5. Full required payment is the recommended MVP clearance rule for website/call-center-style Orders before production release.
6. A verified partial Payment is not Order Payment Clearance. Underpayment, overpayment, and unknown amounts cannot be silently treated as full payment.
7. Verification, financial persistence, accounting posting, customer projection, and production release are distinct facts even when one UI coordinates them.
8. Repeated delivery or a lost response must converge on one logical financial outcome.
9. Original and replacement proofs and decisions remain historically traceable; they are never silently overwritten.
10. Cancellation after payment requires a controlled financial workflow and never deletes or silently rewrites Payment history.

## Current-System Evidence

### Laravel backend

- `Payment` belongs to an `Invoice`, records method, configured payment method, amount, optional `reference_number`, payment time, branch, executing user, and optional subledger entity. It has no proof binary/reference or verification lifecycle.
- `InvoiceFromOrderService` creates a `draft` Invoice from non-cancelled Order items. The Call Center payment path rejects payment if that draft Invoice does not already exist.
- `InvoicePaymentService::recordInvoicePayment` persists a `Payment`, sets the Invoice to `partial` or `paid`, closes it when fully paid, and currently asks `AccountingService` to create an Invoice journal entry only on full payment. The exact future timing remains EA-05, not an E-06 approval.
- `CallCenterOrderExecutionService::confirmBankTransferAndRelease` accepts active non-entity `bank`, `card`, or `wallet` methods, requires a reference, creates a `PaymentConfirmation`, records the Invoice Payment, and then separately attempts kitchen release only if the Invoice is fully paid.
- The financial phase locks the Order and wraps idempotency, confirmation, Payment creation, Invoice state, and the current accounting call in a database transaction. A committed idempotency record makes an identical retry return to release evaluation rather than create another Payment.
- Kitchen release is a separate transaction using `OrderConfirmationService`; failure leaves payment committed, returns the Order to `pending`, and sets `kitchen_release_status=release_failed` for retry/reconciliation.
- Partial payment is supported: positive amounts up to the Invoice remainder create a Payment and leave the Order `processing`/held. Overpayment is rejected by the current service. Full payment changes Order payment state to paid before release is attempted.
- Manual reference confirmation is currently authorized by the `execute-call-center-payment` Gate. Its policy permits super-admin, branch-manager, accountant, call-center, or a user with `manage-call-center`. This is existing behavior, not the final target permission design; E-06 requires an independent payment-verification capability.
- `payment_confirmations` uniquely constrains `(payment_method_id, normalized_reference_number)` and uniquely stores its idempotency key. A separate migration changes `payments.reference_number` from per-Invoice uniqueness to global uniqueness. Actual production migration state remains unverified.
- Existing confirmation records contain confirmed/rejected constants, but the inspected execution path creates only `confirmed`; no proof review, rejection workflow, Needs Review/Financial Review workflow, replacement evidence, proof preview, or proof-access audit exists.
- Seeded configured methods are Cash, Bank Transfer, Credit Card, Digital Wallet, Customer Account, Employee Account, and Supplier Account. Customer/employee/supplier methods are entity-account debits, not proof-confirmation methods. Not every method requires customer-uploaded evidence.
- Accounting has posted-transaction reversal support. No inspected website-payment refund/void orchestration safely covers the paid-Order cancellation case.

### Admin React application

- The Call Center UI loads configured methods, requires references for card/bank/wallet, collects exact full checkout coverage, creates an Order/Invoice, then executes payment. It displays the successful result as paid and sent to the kitchen.
- Other POS and sales-Invoice screens support cash/card/wallet/bank-style references, payment status, and partial/paid concepts.
- No inspected screen provides Payment Proof intake/preview, proof-version history, verification/rejection reasons, independent payment-review permission, duplicate-reference investigation, Finance Review, or verification audit.
- Existing payment execution must therefore remain distinct from the future Payment Proof review workflow, even where Phase F reuses safe Laravel domain services.

### Next.js website

- Checkout currently collects customer/order details, produces a WhatsApp message, opens `wa.me`, and clears client cart state.
- No inspected Payment screen, bank/app instructions, payment-reference input, Payment Proof upload, card gateway/callback, payment-status page, transaction identifier, or payment retry exists.
- E-06 therefore designs target architecture; it does not approve or describe an existing website payment implementation.

### Evidence qualification

These findings are based on repository code and migrations, not the live production database or deployed configuration. Runtime migration state, configured methods, permissions, and financial data remain part of the runtime-verification backlog.

## Decision Drivers

- Durable upload while a branch, Sync Agent, or Laravel is unavailable.
- Clear separation of private evidence custody from financial authority.
- No false confirmation, duplicate Payment, lost proof, or proof-driven kitchen release.
- Compatibility with existing Invoice/Payment and Call Center services.
- Recoverable correlation after timeout or lost acknowledgement.
- Least-privilege evidence access, auditability, and future mobile reuse.
- Explicit handling of partial, overpaid, duplicated, rejected, and ambiguous cases.

## Terminology

- **Payment Proof:** private customer-supplied evidence associated with a claimed payment. It is not a normal Message Attachment and is not itself a Payment.
- **Proof Received:** Cloud durably accepted the evidence and can truthfully state that review is pending.
- **Payment Verified:** Laravel made an authorized, audited decision that the bound payment claim is valid and obtained the durable local financial/payment result required by the approved contract. It does not by itself mean accounting is posted or production is released.
- **Payment Rejected:** Laravel authoritatively found that the reviewed claim cannot be accepted. It creates no confirmed Payment effect from that proof.
- **Needs Finance Review / Needs Review:** the result is unknown, conflicting, duplicated, or financially indeterminate; it is neither Verified nor Rejected.
- **Payment Cleared:** Laravel has authoritative evidence that the Order satisfies the applicable payment policy. For MVP website/call-center-style Orders, the required amount must be fully verified.

The lifecycle labels in this ADR are conceptual and are not final enum names; EA-02 owns canonical vocabulary.

## Options Considered

### Option A — Cloud private evidence store plus Laravel-authoritative verification

Cloud durably stores and governs the uploaded binary. Laravel reviews the correlated evidence and alone establishes verification, financial outcome, clearance, and production eligibility.

### Option B — Laravel-only evidence and verification

Laravel receives and stores the proof and makes the decision. Financial authority is clear, but public intake becomes coupled to branch/Laravel availability and expands sensitive evidence handling inside the operational application.

### Option C — Cloud evidence and Cloud verification

Cloud receives evidence and establishes final verification. This improves intake locality but splits financial authority, risks false confirmation and duplicate effects, and conflicts with E-01.

## Detailed Comparison

| Criterion | Cloud Evidence + Laravel Verification | Laravel-only Evidence | Cloud Verification |
| --- | --- | --- | --- |
| Branch outage upload | Strong | Weak | Strong |
| Private evidence durability | Strong | Acceptable | Strong |
| Financial authority safety | Strong | Strong | High Risk |
| Laravel integration | Acceptable | Strong | Weak |
| Customer availability | Strong | Weak | Strong |
| Verification audit | Strong | Acceptable | Weak |
| Duplicate handling | Strong | Acceptable | High Risk |
| Payment reference control | Strong | Strong | Weak |
| Reconciliation | Strong | Acceptable | High Risk |
| Sensitive-data exposure | Acceptable | Weak | Acceptable |
| Scalability | Strong | Acceptable | Strong |
| Future mobile support | Strong | Weak | Strong |
| Risk of false payment confirmation | Strong | Strong | High Risk |
| Production safety | Strong | Strong | High Risk |
| Authority clarity | Strong | Strong | High Risk |

## Recommended Authority Model

Recommend **Option A — Cloud Private Payment Evidence Store + Laravel-authoritative Payment Verification**.

```text
Customer uploads proof
→ Cloud private evidence store
→ Proof Received / Under Review
→ E-02 durable synchronization
→ authorized Laravel review
→ authoritative Laravel payment result
→ Laravel Outbox
→ Cloud customer-safe projection
→ Laravel separately evaluates production release
```

Only Laravel may establish `Payment Verified`, `Payment Rejected`, or `Payment Needs Review`. Future OCR/provider automation may supply review input but cannot bypass Laravel's approved verification contract.

## Payment Proof Storage

The Cloud private evidence store is authoritative for the original uploaded binary and its evidence lineage. Conceptually it retains an opaque proof reference, Portal uploader, staged/Order correlation, payment-method context, customer-supplied reference and amount, upload time, safe filename metadata, validated content metadata, integrity checksum, scan result, evidence version, and access audit.

Laravel consumes an authorized, purpose-bound reference/projection. Routine synchronization must not copy the binary into normal messages, logs, or public URLs. Exact schema and cardinality remain Phase F work; one proof is not assumed to equal one Payment because replacement proofs, duplicate uploads, multiple partial payments, and two proofs for one transaction are possible.

## Private Evidence Security

Required controls are private object storage, encryption in transit and at rest, MIME/content validation, allowlisted types, file-size limits, malware scanning, integrity checksum, short-lived authorized access, access audit, least privilege, purpose-bound access, and exclusion of sensitive content from routine logs. There must be no public permanent URL.

Provider, MIME list, size limit, signed-access duration, scan product, and encryption technology remain Phase F/security decisions. Retention and deletion remain EA-06.

## Payment Evidence Lifecycle

Conceptually:

```text
Proof Uploaded → Proof Received → Waiting for Local Review → Under Review → Verified
```

Alternative outcomes include Rejected, Needs Customer Action, Needs Finance Review, Needs Review, Superseded, and Cancelled. A replacement creates V2 and preserves V1; it never mutates V1 in place. Only reviewed evidence bound to the decision can support verification.

## Proof Receipt vs Verification

Cloud may immediately communicate “We received your payment evidence and it is under review” after durable acceptance. It must not communicate “Payment confirmed” until Laravel commits or recoverably identifies the required local result and publishes that authoritative outcome.

```text
Payment Proof Uploaded ≠ Payment Received as a financial fact
Payment Proof Uploaded ≠ Payment Verified
Payment Proof Uploaded ≠ Laravel Payment record
Payment Proof Uploaded ≠ Invoice settled
Payment Proof Uploaded ≠ Accounting posted
Payment Proof Uploaded ≠ Kitchen release
```

## Verification Authority

Laravel alone decides the outcome. A decision conceptually binds Order reference, proof reference/version, payment method, expected amount, submitted amount where applicable, submitted transaction/reference, actual verifier, decision time/outcome/reason, and correlation. Provider destination, currency, branch, and Invoice reference may also be required by the later financial contract.

`Payment Verified` means that Laravel has accepted the payment claim and that the durable corresponding Laravel payment/financial result required by the approved contract is known. It does not mean `Accounting Posted`, and it does not mean `Production Released`.

## Verifier Authorization

Routine verification may be performed by an employee with a specific payment-verification permission and applicable branch/scope. Finance owns ambiguous, conflicting, potentially duplicated, or financially indeterminate cases; supervisors may receive governed escalation authority. The actual actor, not only a role label, is recorded.

Existing `execute-call-center-payment` authorization is evidence of current execution behavior, not approval to reuse its broad role list as the future proof-verification permission.

## Separation of Duties

The same employee may perform Order approval and routine payment verification only when separately granted both capabilities. They remain two independent decisions with separate times, inputs, reasons, and audit records. High-risk maker-checker thresholds, if any, are deferred; E-06 invents none.

## Reference Validation

Reference reuse must be detected. Current code normalizes manual references and uniquely constrains them per configured method in `payment_confirmations`; a migration also intends global uniqueness on non-null `payments.reference_number`. The target contract must reconcile both controls and actual deployed migration state.

- Same reference and same logical retry: recover and return the prior outcome.
- Same reference with another Order, customer, method, or amount: do not reassign or create another Payment; enter Needs Finance Review or reject according to policy.
- Missing required reference: proof-based payment cannot be verified.
- Malformed reference: reject or request correction.

Exact scope and idempotency keys remain EA-03/E-10 and Phase F.

## Amount Validation

The verifier compares expected Order/Invoice amount, customer-submitted amount, reviewed evidence, and the proposed Laravel Payment amount. Exact, underpaid, overpaid, and unknown/ambiguous outcomes are distinct.

- Exact required amount may establish full clearance after the Laravel result commits.
- Underpayment may create an accepted partial Payment only if policy permits, but the Order remains uncleared and held.
- Overpayment is rejected by the current Call Center service. A future accepted overpayment needs an explicit EA-05 financial/refund policy and cannot be silently clipped or treated as full payment.
- Unknown or inconsistent amount enters Needs Review/Finance Review.

## Partial and Overpayment

Current Laravel Call Center behavior supports multiple partial payments up to the remaining Invoice balance and blocks kitchen release until fully paid. The recommended website MVP preserves this distinction but requires full verified required payment for clearance. Accepting remote partial payments may be supported later without redefining them as clearance.

Overpayment has no safe current website path. It must not create an arbitrary extra Payment, account credit, or silent refund. It enters governed review until EA-05 defines the financial treatment.

## Proof Replacement

Unreadable, incomplete, incorrect, wrong-transaction, or wrong-amount evidence may lead to Needs Customer Action or Rejected. A new upload creates a new version/reference correlated to the same claim; all earlier proofs and their decisions remain auditable subject to EA-06. A safe customer reason may say reference not verified, amount mismatch, unreadable evidence, payment not found, or updated proof required without exposing internal banking data.

## Verification Audit

Every review preserves Order, proof/version, method, reference, expected and verified amount, verifier, decision, safe/internal reason as applicable, decision time, prior decision/correction lineage, and correlation. Proof access, replacement, duplicate detection, Payment creation, Invoice result, reconciliation, cancellation, refund/void action, and production release are traceable. Corrections use governed new decisions/reversals; no proof, decision, Payment, or financial correction is silently overwritten.

## Payment Clearance

```text
Payment Clearance
= Laravel has authoritative evidence that the Order satisfies its payment policy
```

Recommended MVP:

```text
Required Amount Fully Verified → Payment Cleared
```

An accepted partial Payment is not clearance. Clearance is derived from authoritative Laravel financial state and policy, not a Cloud flag or proof count.

## Verification vs Financial Posting

The verification decision and required Laravel Payment/financial persistence should occur in one atomic local transaction where the approved financial contract requires a Payment. A customer-visible Verified result must not exist while that required result is indeterminate.

Current Call Center code transactionally creates `PaymentConfirmation`, `Payment`, updates Invoice/Order state, and invokes current full-payment accounting behavior. E-06 does not approve that exact posting sequence for the target. Exact Invoice officialization, Payment posting, Journal Entry posting, receivable clearing, settlement recognition, and refund/void timing remain **EA-05 — Financial Posting Timing**. An unknown posting outcome enters Needs Finance Review, not false confirmation.

## Production Release Boundary

```text
Laravel Order
→ Payment Cleared
→ Laravel validates operational eligibility
→ explicit Kitchen Release
→ Production Ticket
```

`Payment Verification ≠ Production Release`. Cloud cannot release production. Laravel must revalidate Order status, full clearance, branch/item/production eligibility, cancellation/conflict state, and release idempotency. Existing Call Center behavior demonstrates the correct separation: a committed payment may coexist with `release_failed` and later release reconciliation.

## Paid Order Cancellation Before Production

`Paid + not production-released` is no longer the simple unpaid cancellation allowed by E-05. Laravel may permit cancellation only through a controlled, authorized cancellation plus refund/void workflow that records reason, actor, Payment/Invoice consequences, and reconciliation. Cancellation must not delete the Payment or silently mark it unpaid. Whether the correct financial action is refund, void, credit, reversal, or another treatment remains EA-05.

## Post-Production Cancellation

Once production is released, ordinary cancellation is blocked or escalated into an exception/waste/service-recovery workflow under Laravel policy. Payment reversal alone cannot erase production history. E-08 will define concurrent cancellation/release conflict rules.

## Refund/Void Boundary

E-06 establishes only that a paid cancellation needs a separate governed financial operation and customer projection. It does not implement or select refund/void semantics, gateway behavior, ledger timing, or authority thresholds. Existing accounting reversal capability is supporting evidence, not a complete Order-refund workflow.

## Duplicate and Timeout Recovery

At-least-once delivery requires the same logical verification to produce the same logical Payment outcome. Duplicate proof upload is correlated or versioned; it does not imply another payment. Duplicate verification delivery queries durable identity/correlation and returns the prior result. A conflicting reference goes to review.

If Laravel commits the Payment but the acknowledgement is lost, retry must query durable verification/idempotency/reference correlation, recover the committed Payment result, and reconcile the Cloud projection. It must never create a second Payment merely because the caller timed out. EA-03 defines exact keys; E-07 defines retry and reconciliation timing.

## Offline and Failure Behavior

| Scenario | Cloud Evidence | Laravel | Customer State | Required Behavior |
| --- | --- | --- | --- | --- |
| Branch internet offline | Proof remains durable/private | Unreachable | Received/Under Review | Queue for later sync; never confirm payment. |
| Sync Agent offline | Proof remains durable/private | No review command/result transfer | Received/Under Review | Resume/reconcile under E-02/E-07. |
| Laravel offline | Proof remains durable/private | No authoritative decision | Received/Under Review | Do not infer verification from delay. |
| Cloud unavailable during upload | No durable receipt unless commit succeeded | Unchanged | Upload failed/unknown | Retry same logical submission; do not claim receipt. |
| Proof uploaded twice | Preserve/correlate versions or duplicate evidence | No duplicate effect | Received/Under Review | Deduplicate or relate safely; one proof count is not one Payment. |
| Verification command delivered twice | Same evidence | Recover prior outcome | Stable prior result | Idempotent financial effect; no second Payment. |
| Laravel Payment committed but ACK lost | Projection stale | Payment/result durable | Under Review/unknown until reconciled | Query correlation and republish outcome. |
| Duplicate reference detected | Evidence preserved | Conflict/review, no new Payment | Needs Review | Never silently reassign reference. |
| Proof rejected | Evidence retained privately | No confirmed Payment from proof | Rejected/Needs Customer Action | Safe reason; no clearance/release. |
| Replacement proof uploaded | V1 retained; V2 new version | Review V2 explicitly | Under Review | Never overwrite V1 or reuse its decision silently. |
| Paid Order cancelled before production | Evidence retained | Controlled cancel + refund/void workflow | Cancellation/financial action pending | No deletion or silent refund. |
| Production already released | Evidence retained | Block/escalate operational exception | Not ordinary cancellation | Preserve production/financial audit. |

## Data Ownership Matrix

| Data | Final Authority | Cloud Storage/Projection | Laravel |
| --- | --- | --- | --- |
| Payment Proof binary | Cloud evidence store | Private authoritative binary | Authorized short-lived access/reference only |
| Proof metadata | Cloud for uploaded evidence facts | Authoritative evidence metadata/audit | Review projection/correlation |
| Proof upload receipt | Cloud | Authoritative durable receipt | Optional projection |
| Customer-entered payment reference | Cloud for submitted claim | Original claim | Validates and binds accepted financial use |
| Verification decision | Laravel | Customer-safe projection | Authoritative decision/audit |
| Verified amount | Laravel | Customer-safe projection | Authoritative amount |
| Payment record | Laravel | Minimal customer projection | Authoritative record |
| Invoice financial state | Laravel | Minimal customer projection | Authoritative state |
| Accounting posting | Laravel | No authority; normally not exposed | Authoritative state |
| Payment clearance | Laravel | Customer-safe projection | Authoritative derived fact/policy |
| Production eligibility | Laravel | Customer-safe projection only | Authoritative evaluation |
| Refund/void | Laravel | Customer-safe projection | Authoritative governed operation |
| Customer payment-status projection | Laravel-sourced fact; Cloud presentation | Authoritative customer inbox/projection | Publishes source outcome |

## Notifications

Proof receipt may trigger an immediate Cloud receipt notification. Verified, Rejected, Needs Customer Action, refund/void, and clearance communications require the corresponding authoritative fact. Cloud must deduplicate at-least-once outcomes and must not turn stale/unknown state into “Payment confirmed.” E-04 remains the Notification-store authority.

## Reporting

Operational evidence metrics include Proofs Uploaded, Awaiting Review, Rejected, Replaced, duplicate-reference cases, verification time, Finance Review cases, clearance time, paid cancellations before production, and refund/void requests. Financial reporting uses Laravel authoritative Payment/Invoice/accounting records. Proof Uploaded count is never Paid Orders count.

## Privacy and Retention

Payment Proof is high-sensitivity, purpose-limited private evidence. Least privilege, private storage, short-lived access, access audit, minimum projection, and minimal logging are mandatory. EA-06 later defines retention duration, deletion eligibility, legal/business holds, customer export, redaction, and purpose limitation.

## Impact on Other Decisions

| Decision | E-06 Impact |
| --- | --- |
| E-07 Offline and Retry | Defines retry, terminal failure, and proof/verification/result reconciliation timing. |
| E-08 Conflict Resolution | Resolves concurrent payment, cancellation, replacement-proof, review, and production-release conflicts. |
| E-09 Unified Customer Identity | Owns identity binding; evidence cannot silently merge customers. |
| E-10 External References | Defines stable opaque proof, Order, Invoice/Payment, and verification correlations without public Laravel IDs. |
| EA-01 Portal Authentication | Authenticates uploader/customer ownership and governs evidence access. |
| EA-02 Status Dictionary | Finalizes payment/review/clearance status vocabulary and mappings. |
| EA-03 Idempotency | Defines proof-submission, verification, Payment, and result-publication keys/scope/retention. |
| EA-04 Catalog/Pricing Authority | Supplies the accepted commercial amount against which payment policy is evaluated. |
| EA-05 Financial Posting Timing | Defines Invoice, Payment, Journal, receivable, refund, void, and settlement timing. |
| EA-06 Privacy and Retention | Defines Payment Proof retention, deletion, holds, export, and redaction. |

## Consequences

Option A permits durable customer uploads during branch outages and keeps sensitive binary evidence out of routine Laravel storage while preserving one financial authority. It requires secure Cloud evidence infrastructure, purpose-bound access from Laravel, durable cross-system correlation, review tooling, and reconciliation. Customer confirmation may wait for local availability; that delay is preferable to false financial truth.

## Risks

| Risk | Conceptual mitigation |
| --- | --- |
| Fake/altered proof or verifier error | Authorized review, checksum/versioning, reason/audit, escalation and governed correction. |
| Proof malware | Allowlist, content validation, scanning, quarantine, no routine inline exposure. |
| Unauthorized evidence access | Private storage, least privilege, short-lived access and access audit. |
| Duplicate reference or Payment | Normalization, uniqueness/correlation, idempotency, conflict review and reconciliation. |
| Underpayment/overpayment | Compare all amounts; partial remains held; overpay/unknown enters review. |
| Wrong Order/customer proof | Bind exact opaque references and authenticated uploader; no identity merge. |
| Payment committed but ACK lost | Query durable correlation and republish; never repeat the financial effect. |
| Invoice/Payment inconsistency | Atomic local contract where required; Finance Review and reconciliation. |
| Incorrect confirmation notification | Publish only Laravel-authoritative committed result; deduplicate projection events. |
| Kitchen released before clearance | Laravel-only full-clearance and operational release gate. |
| Paid cancellation without refund handling | Controlled cancellation plus separate EA-05 refund/void workflow. |
| Proof retained too long | EA-06 retention/deletion/hold policy and auditable lifecycle. |
| Financial permission abuse | Separate explicit permission, actor audit, scope, escalation and future maker-checker policy. |

## Mitigations

The invariant set, private evidence controls, Laravel-only authority, independent permissions, amount/reference validation, immutable evidence lineage, atomic/recoverable local outcomes, Needs Finance Review state, explicit clearance rule, and separate production/cancellation/refund gates are mandatory implementation constraints for Phase F.

## Rejected Alternatives

- Reject `Proof existence = Payment Verified`.
- Reject Cloud independently establishing final payment truth.
- Reject public URLs or general Message Attachment storage for Payment Proof.
- Reject retrying an unknown committed outcome into another Payment.
- Reject silent overpayment adjustment, reference reassignment, decision overwrite, Payment deletion, or refund inference.
- Reject combining Order approval permission, payment verification permission, and production release into one implicit action.

## Implementation Implications for Phase F

Phase F will need private evidence intake/access, stable opaque references, evidence-version and review projections, explicit Laravel authorization, a transactional verification-to-financial contract aligned with EA-05, Inbox/Outbox/idempotency/reconciliation aligned with E-07/E-10/EA-03, safe customer projections, and separate production/cancellation/refund operations. No class, table, endpoint, job, storage provider, UI, or enum is approved here.

## Architecture Review Questions

The following **10 questions** are recommendations for review and are not approved answers:

1. Do we approve Cloud private Payment Proof storage plus Laravel-authoritative Payment Verification? **Recommendation:** Yes.
2. Does durable Proof upload mean only Proof Received / Under Review and never Payment Verified? **Recommendation:** Yes.
3. May routine Payment Verification be performed by a specifically authorized Call Center/operations employee, while financially ambiguous cases go to Finance Review? **Recommendation:** Yes, with independent payment-verification permission and actor audit.
4. May the same employee both approve the Website Order and verify payment if they possess both permissions? **Recommendation:** Yes for routine cases, but treat them as two distinct audited decisions; do not approve thresholds or maker-checker rules yet.
5. Should Payment verification and production release remain separate domain operations even when the UI coordinates them? **Recommendation:** Yes.
6. For website/call-center-style Orders, must full required payment be verified before production release? **Recommendation:** Yes; an accepted partial Payment remains uncleared and held.
7. Should ambiguous reference, amount, or commit outcomes enter Needs Finance Review / Needs Review instead of being silently verified, rejected, or retried into another Payment? **Recommendation:** Yes.
8. Should Payment Proof revisions/replacements preserve original evidence and audit history rather than overwrite it? **Recommendation:** Yes.
9. If a Payment is verified but production has not started, may Order cancellation occur only through a controlled cancellation plus refund/void financial workflow? **Recommendation:** Yes; this is not the simple unpaid cancellation from E-05.
10. Should exact Invoice, Payment, accounting, and refund posting timing remain deferred to EA-05 while E-06 defines Payment Verification and Payment Clearance only? **Recommendation:** Yes.

## Traceability

- E-01: independent Cloud evidence custody and Laravel financial authority.
- E-02: at-least-once durable transfer, acknowledgement, and reconciliation.
- E-03/E-04: Payment Proof is separate evidence and upload is not verification; customer communication follows authority.
- E-05: accepted Laravel Order precedes the separate payment and production gates.
- D1/D2: private proof handling, authorized staff review, full-payment production safety, audit, and financially safe ambiguity.
- Current code: `Payment`, `Invoice`, `PaymentConfirmation`, `InvoiceFromOrderService`, `InvoicePaymentService`, `CallCenterOrderExecutionService`, `OrderConfirmationService`, idempotency and reference migrations, Call Center Admin payment collection, and website WhatsApp checkout.
- Deferred: E-07 through E-10 and EA-01 through EA-06 remain pending; Phase F remains not started.
