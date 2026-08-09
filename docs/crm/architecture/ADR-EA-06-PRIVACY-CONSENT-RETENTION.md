# ADR EA-06 — Privacy, Consent and Retention

## Status

**[x] Approved — 2026-08-09**

## Approved Decision

Terms Acceptance, required privacy acknowledgement/consent, Marketing Consent, Occasion Consent, and Family-feature Consent are separate. Registration never implies Marketing Consent. Marketing Consent is optional, explicit, unchecked by default, versioned, withdrawable, and audited; withdrawal neither deletes the Customer nor disables the Portal Account.

Each system stores only what its authority/function needs: Laravel does not store Portal passwords, Cloud does not become an accounting ledger, and sensitive projections are minimal.

## Sensitive Evidence and Access

Payment Proof uses private encrypted object storage, no public object URL, malware/content validation, scoped authorized access, and access audit. A signed URL is a temporary access artifact, not resource identity.

Full phone reveal, Customer financial data, Payment Proof, identity recovery, Customer export, merge/unlink evidence, and sensitive security context require specific permission and audit beyond basic Customer360 access.

Logs exclude passwords, OTP secrets, private proof contents, unnecessary signed URLs, access tokens, and unnecessary full PII. Typed E-10 references are preferred. Deleted/unlinked/merged Customer, Order, Link, and source references are never reused.

## Governed Retention

No fixed automatic financial-data deletion period is approved. Financial, Accounting, AR, Invoice, Payment, and Order financial records default to retention with **no automatic destructive TTL deletion**. Architecture supports a configurable, versioned, governed, audited policy per data class, including effective date, permitted action, archive/anonymize/delete choices, manual review, hold override, actor, and reason.

Messages, Notifications, Complaints, Payment Proof, Identity Reviews, authentication audit, and consent evidence likewise use configurable data-class retention rather than hard-coded destructive periods.

```text
Operational Expiration ≠ Record Retention ≠ Destructive Deletion
```

Session, OTP, or signed-URL expiry does not require immediate deletion of related audit evidence. Governed disposal supports Retain, Archive, Anonymize, or Delete where allowed, with authorization, reason, policy version, and audit.

Privacy/deletion requests determine what may be removed or anonymized while preserving required operational, financial, audit, and referential truth. Legal/Financial/Complaint/Security/Business Holds may suspend disposal and record scope, reason, actor, creation, release, and audit; they are not undocumented permanent flags.

Architecture configuration does not override applicable legal or financial obligations and claims no unverified fixed legal period.

## Architecture Review Decisions

1. **Approved:** consent purposes are separate; Marketing Consent is optional, explicit, withdrawable, versioned, and audited.
2. **Approved:** data minimization follows system authority and function.
3. **Approved:** Payment Proof remains private, encrypted, validated, scoped, and audited.
4. **Approved:** no fixed automatic financial deletion period exists; financial records default to no destructive TTL.
5. **Approved:** retention is configurable, versioned, governed, and audited per data class.
6. **Approved:** operational expiry, retention, and destructive deletion are distinct.
7. **Approved:** disposal supports governed Retain/Archive/Anonymize/Delete actions and documented Holds.
8. **Approved:** privacy requests preserve required financial/audit truth and referential integrity.
9. **Approved:** sensitive staff access is separately permissioned/audited and secrets/private content are excluded from logs.
10. **Approved:** E-10 reference non-reuse survives disposal, and policy cannot override legal/financial obligations.

## Phase F Implications

Phase F defines policy schema/UI, safe defaults, access enforcement, audit implementation, object lifecycle, anonymization/deletion mechanics, holds, export workflows, and legal/business configuration. No implementation is approved or performed here.
