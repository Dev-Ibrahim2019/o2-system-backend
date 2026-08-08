# ADR E-09 — Unified Customer Identity

## Status

**[R] Proposed — Ready for Architecture Review**

Recommended: **Option A — Federated Portal Identity + Laravel Canonical Customer + Explicit Governed Identity Link**.

This proposal defines identity authority, linking, Limited Account, merge, unlink, organization, access, conflict, and audit boundaries. It does not approve authentication/session mechanics, schemas, identifiers, final statuses, idempotency details, financial posting, retention, or Phase F implementation.

## Context

The public Portal needs a login identity while the restaurant already has Laravel Customer records that own operational and financial history. Collapsing these identities, or treating a phone as their permanent key, can disclose Orders, Loyalty, Complaints, addresses, or financial data to the wrong person.

Core rules:

```text
Portal Account ≠ Laravel Customer.
Verified Phone ≠ permanent Customer ID.
Identity Link = security-sensitive authorization boundary.
```

## Approved E-01 through E-08 Constraints

- Laravel remains authoritative for Customer business data and operational/financial history; Cloud owns the public integration side (E-01).
- Durable synchronization is hybrid, at-least-once, and reconciled (E-02).
- Conversation, Notification, staged Website Order, Payment Proof, retry, and conflict authorities remain exactly as approved by E-03 through E-08.
- Website submission remains a Cloud-authoritative staged intent until conditional Laravel acceptance of one exact revision (E-05/E-08).
- Lost acknowledgements use durable reconciliation, not duplicate identity decisions (E-07).
- Link, merge, and unlink races use authority-aware conditional resolution, never timestamp Last Write Wins (E-08).

## Current-System Evidence

### Laravel backend

- `Customer` is a soft-deleted Laravel business entity with stable database identity and relationships to phones, addresses, Orders, invoices, complaints, notes, and occasions.
- Customer financial balance is derived from the Laravel customer subledger; Customer records also carry credit limit, opening balance, payment terms, risk, and CRM classification fields.
- Loyalty currently exists as `customers.loyalty_points`; no separate immutable loyalty ledger was found in the inspected model/migrations, so merge correctness is an implementation gap.
- `customer_phones` supports multiple phone rows per Customer and primary/verified flags. Its `normalized_phone` is globally unique in the migration. Legacy `customers.phone` and `customers.mobile` also remain, are nullable, and are not unique.
- Current identity services normalize phones and reject a normalized phone already owned by another `customer_phones` row. Call Center resolution also searches normalized phone/tail evidence. A schema constraint does not prove that legacy or production data has no duplicates; runtime migration/data verification remains required.
- Orders have nullable `customer_id` and also retain `customer_name`, `customer_phone`, and delivery snapshots. Therefore Laravel can technically retain an isolated contact snapshot, but accepted Customer-owned history and downstream invoice/complaint/loyalty behavior still require an intentional Customer association policy.
- Complaints, invoices, feedback, and accounting entries relate to Laravel Customer identity. Customer AR uses `subledger_type = customer` plus the Customer ID.
- No governed Customer merge, Portal link/unlink, identity-review, organization-membership, or Company Customer model was found.
- The Customer record has business-oriented fields such as tax number, website, payment terms, and credit data, but no explicit individual/company discriminator. Company identity is therefore an architecture gap.

### React CRM

- The Admin/Call Center frontend supports Customer phone search, quick Customer creation, Customer360/profile, addresses, Orders, complaints, loyalty display, and financial/account views.
- Existing flows expose Customer operational and financial context through Laravel APIs and permissions.
- No dedicated duplicate-resolution, Customer merge, Portal identity-link, unlink, identity-review, organization-membership, or Company-authorization workflow was found.
- Current phone editing/search UI must not be treated as proof of Portal identity authority.

### Next.js website

- The inspected website currently provides menu, branch, reservation, rating, and local dining experiences.
- No Portal Account persistence, registration/login/session flow, Customer link, account history, loyalty, saved-address, checkout Customer identity, or Company membership implementation was found.
- E-09 therefore defines target architecture. It does not endorse client state, local storage, phone, or email as Customer authority.

## Decision Drivers

- Prevent historical-data disclosure and loyalty/financial misattribution.
- Support duplicate legacy Customers, shared and recycled phones, and phone changes.
- Preserve Laravel business authority without making Cloud a second Customer master.
- Permit a safe, useful Limited Account and fast Website ordering.
- Preserve audit lineage across link, merge, unlink, and company access.
- Remain recoverable under E-07 and conflict-safe under E-08.

## Terminology

- **Portal Account:** human/public Cloud authentication identity.
- **Laravel Customer:** canonical restaurant business Customer.
- **Identity Link:** governed authorization granting a Portal Account access to a Customer context.
- **Limited Account:** authenticated/verified Portal identity without a safe canonical Customer link; neither blocked nor anonymous.
- **Identity Review:** governed internal case for ambiguous evidence, candidates, links, merge, unlink, or organization access.
- **Organization Customer:** Laravel business Customer representing a company.
- **Organization Membership:** explicit authorization for a human Portal Account to act for an Organization Customer.

## Options Considered

### Option A — Federated Identity + Explicit Customer Link

Cloud Portal Account + Laravel canonical Customer + an explicit Laravel/CRM-authoritative link.

### Option B — Phone Number Is the Customer Identity

Phone becomes Portal Account ID and Customer ID.

### Option C — Duplicate Customer Master

Cloud and Laravel both edit Customer master data and attempt synchronization/merge.

## Detailed Comparison

| Criterion | Federated Link | Phone as Identity | Dual Customer Master |
| --- | --- | --- | --- |
| Duplicate Customer safety | Strong | High Risk | Weak |
| Phone-change safety | Strong | High Risk | Weak |
| Recycled-number safety | Strong | High Risk | Weak |
| Historical-data privacy | Strong | High Risk | High Risk |
| Loyalty correctness | Strong | High Risk | Weak |
| Financial correctness | Strong | High Risk | High Risk |
| Merge safety | Strong | Weak | High Risk |
| Company support | Strong | High Risk | Weak |
| Offline behavior | Acceptable | Weak | High Risk |
| Cloud/Laravel authority clarity | Strong | Weak | High Risk |
| Auditability | Strong | Weak | Weak |
| Portal UX | Acceptable | Strong | Acceptable |
| Legacy data compatibility | Strong | High Risk | Weak |
| Scalability | Strong | Acceptable | Weak |
| Implementation complexity | Acceptable | Strong | High Risk |

## Recommended Identity Model

```text
Portal user proves control of Portal identity
→ Cloud supplies verified identity evidence
→ Laravel evaluates authoritative Customer candidates
→ one safe candidate: commit governed Link
→ no safe candidate: create Website-source Customer and Link
→ multiple/unsafe candidates: Limited Account + Identity Review
→ Cloud receives a minimal customer-safe Link projection
```

Option A is recommended. Phone is mutable, shareable, inconsistently stored, and recyclable. Dual editable masters create irresolvable ownership of Orders, Loyalty, Complaints, and financial history.

## Identity Authorities

### Portal Account

Cloud owns the public login identity and verified-phone claim. Exact OTP, password, recovery, device trust, and session rules remain EA-01.

### Laravel Canonical Customer

Laravel owns the Customer business record, history, Orders, Loyalty ownership, Complaints, financial relationship, CRM classification, and operational restrictions.

### Identity Link Authority

Laravel/CRM is final authority for granting, changing, or removing Portal Account ↔ Customer linkage because that decision unlocks protected history. Cloud stores only a minimal customer-safe projection and may not infer a link from cached Customers.

## Phone Semantics

A verified phone proves current possession of a contact channel, not durable ownership of every Customer row containing that number. Shared family/company phones, legacy duplicates, recycled numbers, replacement numbers, and primary/backup fields make phone unsuitable as Customer identity.

## Phone Normalization

Candidate matching requires canonical normalization before authoritative evaluation. Exact library, country policy, and format remain Phase F. Failure or uncertain normalization is not “no match”; it is Limited/Review as appropriate.

## Safe Automatic Link

Automatic governed linking is only a candidate when all hold:

- Portal phone is verified.
- Exact normalized match exists.
- Exactly one eligible canonical Laravel Customer candidate exists.
- Neither side has a conflicting active link.
- No identity review, merge ambiguity, security restriction, company-contact ambiguity, or stale evidence prevents linking.

Cloud proposes evidence; Laravel re-evaluates and conditionally commits the decision. Similar name, partial phone, email, address, neighborhood, order, or device may support review but never grants historical access.

## Unsafe / Ambiguous Match

Multiple candidates or any violated invariant prohibits automatic selection. Do not pick newest, oldest, highest spend, closest name, or expose candidates to the Portal user. Assign Limited Account and create an internal Identity Review.

## No-Match Customer Creation

After authoritative normalized matching proves no safe candidate, Laravel creates a new `Website/Portal`-source Customer and establishes the governed Link. A failed query or normalization fault is not proof of no match.

## Limited Account

A Limited Account is verified/authenticated but unresolved. It may browse, manage Portal-facing name/contact context, manage safe new addresses, and submit new Website Order intent. It receives no candidate Customer history.

## Limited Account Access

Historical Laravel Orders, existing Loyalty, Complaints, saved Customer benefits/addresses, financial balances, and sensitive CRM data remain blocked until a safe link and any separate authorization are established.

## Limited Account Order Submission

The staged order binds to Portal Account identity plus submitted contact, delivery, and address snapshots. It must not bind to one ambiguous Customer candidate or expose their history.

## Identity Resolution at Order Acceptance

Current Laravel Orders permit nullable `customer_id`, but Customer-owned downstream behavior depends on Customer association. Recommended MVP behavior is:

1. Use the resolved canonical Customer when safe.
2. Otherwise accept time-sensitive eligible Website work through an isolated Website-origin Laravel Customer/context created without merging or exposing ambiguous candidates, retaining the staged contact snapshot and identity-review correlation.
3. Do not silently select a candidate. External-only association may be considered in Phase F only if all Order, invoice, complaint, loyalty, and accounting paths safely support it.

This preserves restaurant speed without granting historical access. Exact creation/reference mechanics remain E-10/Phase F.

## Link vs Merge

Linking a Portal Account to one Customer does not merge other Customer rows. Review may select the correct existing Customer without merging candidates.

## Customer Merge

Merge is a Laravel-authoritative governed operation after evidence establishes the same business Customer. It records proposal, survivor, sources, evidence, reason, reviewer/approver, conflicts, outcome, and audit.

## Financial Merge Safety

Customer merge is not duplicate cleanup. AR subledger entries, invoices, payments, credits, opening balances, credit limits, and Loyalty require preserved reconciliation. Exact financial consequences remain EA-05.

## Merge Lineage

Merge never physically erases identity evidence or destructively rewrites Orders, Payments, invoices, accounting, production, Complaints, or Loyalty history. Source lineage and canonical resolution remain auditable.

## Merge Approval

Use proposal → review → approval. Phone equality alone never merges. Role names and exact permissions remain Phase F.

## Unlink

Unlink removes future Portal authorization to a Customer. It deletes neither identity nor Orders, Payments, or history, and does not change ownership of prior business records.

## Unlink Approval

Permanent unlink preserves D2 second-approval/dual-governance control, reason, actors, effective period, and audit. Cloud removes/repairs the projection after the authoritative commit.

## Emergency Access Block

Suspected compromise may immediately restrict access under EA-01 without waiting for permanent unlink approval. Emergency block and unlink are distinct decisions.

## Relink

Relink requires a new governed decision with current evidence. Never change a foreign key silently; prior compromise/unlink context remains visible to authorized reviewers.

## Portal Phone Change

The new phone requires EA-01 verification and link re-evaluation. It never silently moves the Portal Account to another Customer.

## Customer Phone Change

Laravel contact change preserves stable Customer identity, updates authorized projections, and may require security re-verification. It neither creates another Customer nor reassigns Portal identity automatically.

## Recycled Phone Risk

Verification of a reassigned number does not inherit old history. Link age, conflicting evidence, security signals, and manual review may prevent automatic linking; telecom detection mechanics are deferred.

## Primary / Backup Phones

Phones are Customer attributes/evidence/contact methods. Multiple phones do not create multiple Customer identities, and a backup phone does not automatically establish another Portal link. Login/recovery use remains EA-01.

## Duplicate Portal Accounts

Do not merge Portal Accounts or grant both access based only on name, historical phone, or address. Conflicting links enter governed resolution. Exact Portal Account merge mechanics remain EA-01/Phase F.

## Link Cardinality

MVP normal rule: one Portal Account → one canonical individual Customer; multiple Portal Accounts → one individual Customer is prohibited by default unless a governed use case is approved. Family sharing remains Post-MVP. Organization access uses membership, not an individual link exception.

## Individual Customer

An individual Customer is the Laravel business identity linked to one human Portal Account under the normal MVP rule. Identity remains separate from permissions and restrictions.

## Company / Organization Customer

```text
Human Portal Account ≠ Company Customer.
```

The existing Laravel model lacks an explicit individual/company discriminator, so safe Organization Customer representation is a Phase F architecture gap.

## Organization Membership

```text
Portal Account
→ explicit Organization Membership / Authorization
→ Laravel Company Customer
```

Employee phone equality does not prove company authority. Future scoped roles may exist, but E-09 does not finalize them or assume every member can view all company Orders, Complaints, addresses, contacts, or finances.

## Security Suspension vs Business Restriction

Security suspension may block Portal access. Business restriction may permit login/view while blocking selected actions. Neither is an identity mismatch.

## Historical Order Access

Only a safely linked and authorized Customer context exposes historical Laravel Orders. Limited Accounts may see their own newly submitted Portal activity without receiving candidate history.

## Loyalty

Laravel is final authority. Link determines whose balance/history may be projected; candidate balances are never combined automatically. Merge must preserve lineage.

## Complaints

Limited Accounts receive no candidate Complaint data. A resolved link may expose only the authorized Customer-safe projection and never internal notes or destructive merged history.

## Financial Customer Data

Potential matches never expose AR, balances, invoices, credits, or other financial data. A resolved link plus separate authorization is required; exact Portal-visible fields remain undecided.

## Identity Review

Review covers multiple candidates, conflicting links, duplicate Customers/accounts, recycled phones, organization ambiguity, and merge/unlink requests. It preserves Portal reference, candidates, evidence, conflicts, current state, reviewer, decision, reason, and audit.

## Identity Review Ownership / SLA

CRM/authorized Customer Administration owns business review; Security participates for compromise and Finance for financially sensitive merge. General Call Center staff do not receive arbitrary merge/unlink power. Preserve the target of no more than one business day without promising automatic resolution; safe Limited ordering avoids unsafe access during review.

## Offline / Retry Behavior

- Lost Laravel link acknowledgement is reconciled by stable correlation; no second decision is created.
- Missing verified-phone evidence cannot be assumed; the account stays Limited/Pending.
- When Laravel is offline, Cloud may authenticate per EA-01 but may not grant new historical access from stale assumptions.
- Agent outage queues evidence/commands without bypassing Laravel authority.
- Cloud outage does not block local Customer management; new Portal-facing linkage waits for projection repair.

## Conflict Resolution

Link/link, merge/link, unlink/relink, and phone-change races use E-08 expected state/version preconditions and the first valid authoritative Laravel commit. Stale actions receive explicit outcomes; ambiguity enters review. No Last Write Wins or generic force override.

## Data Ownership Matrix

| Data | Authority | Cloud | Laravel |
| --- | --- | --- | --- |
| Portal Account | Cloud | Authoritative | Reference only as needed |
| Portal verified phone claim | Cloud/EA-01 | Authoritative evidence | Validated evidence projection/reference |
| Customer business identity | Laravel | Customer-safe projection | Authoritative |
| Customer CRM record | Laravel | Minimal authorized projection | Authoritative |
| Portal↔Customer link decision | Laravel/CRM | Proposal + projection | Authoritative |
| Link projection | Laravel-derived | Stored customer-safe copy | Source decision |
| Limited-account state | Split by cause; final vocabulary EA-02 | Portal access enforcement | Identity-resolution decision/input |
| Customer merge | Laravel | Canonical mapping projection | Authoritative |
| Customer unlink | Laravel/CRM | Enforce/remove projection | Authoritative |
| Customer phone/contact data | Laravel for business Customer; Cloud for Portal claim | Portal claim/profile fields | Customer record authority |
| Loyalty ownership | Laravel | Authorized projection | Authoritative |
| Order history ownership | Laravel | Authorized projection | Authoritative |
| Complaint ownership | Laravel | Authorized projection | Authoritative |
| Customer financial relationship | Laravel | No ambiguous exposure | Authoritative |
| Company Customer | Laravel | Authorized projection | Authoritative |
| Company membership/authorization | Laravel/CRM decision | Enforced projection | Authoritative decision |

## Identity Scenario Matrix

| Scenario | Automatic Link? | Limited? | Review? | Customer Creation? |
| --- | ---: | ---: | ---: | ---: |
| Verified phone → exactly one safe Customer | Candidate: Yes | No | No by default | No |
| Verified phone → no safe Customer | No | Temporary | Only if uncertainty | Yes, after authoritative no-match |
| Verified phone → two Customers | No | Yes | Yes | No |
| Customer already linked to another Portal account | No | Yes | Yes | No |
| Portal account already linked elsewhere | No | Existing link governs | Yes | No |
| Phone matches company contact | No | Yes | Yes/membership review | No |
| Customer phone changed | No new link | As policy requires | If evidence conflicts | No |
| Portal phone changed | No silent relink | Yes/preserved restricted access | Re-evaluate | No |
| Suspected recycled phone | No | Yes | Yes | No by default |
| Duplicate Portal accounts | No | Yes as needed | Yes | No |
| Customer merge pending | No | Yes/preserve current safe access | Yes | No |
| Customer unlink pending | No new access | Restricted as security requires | Yes | No |

These outcomes prefer safety over historical disclosure; “automatic” still means an authoritative conditional Laravel decision, not Cloud inference.

## Access Matrix

| Capability | Limited Account | Linked Individual | Organization Member |
| --- | --- | --- | --- |
| Browse | Yes | Yes | Yes |
| Create staged Order | Yes | Yes | Requires later authorization decision |
| Manage new address | Portal-safe new context | Authorized own context | Requires later authorization decision |
| View own newly submitted Portal order | Yes | Yes | Requires later authorization decision |
| Historical Laravel Orders | No | Authorized own history | Requires scoped company authorization |
| Loyalty balance/history | No | Authorized own Customer | Requires later authorization decision |
| Complaints | No candidate history | Authorized own context | Requires later authorization decision |
| Saved Customer addresses | No candidate addresses | Authorized own context | Requires later authorization decision |
| Customer financial data | No | Requires later authorization decision | Requires scoped company authorization |
| Company Orders | No | No unless membership exists | Requires scoped company authorization |
| Company financial data | No | No unless membership exists | Requires explicit later authorization decision |

## Audit

Audit registration identity, verification evidence reference, link proposal/result, auto-link evaluation, Limited assignment, candidate references, merge/unlink proposals and approvals, emergency block, phone change, relink, organization membership changes, actor, reason, time, and correlation. Never log OTP secrets or passwords.

## Privacy

Use least privilege, purpose limitation, minimum evidence, and audited access. Candidate names, balances, Orders, addresses, or Complaints are not shown to the Portal user to resolve ambiguity. Identity evidence, privacy consent, marketing consent, occasion consent, and terms acceptance are separate concepts. Retention/redaction remains EA-06.

## Impact on Other Decisions

| Decision | E-09 Impact |
| --- | --- |
| E-10 External References | Stable opaque Portal/Customer/link/review/merge/unlink/membership references; no public incremental Laravel IDs. |
| EA-01 Portal Authentication | Phone verification, login, sessions, recovery, device trust, and emergency access controls. |
| EA-02 Status Dictionary | Final Limited/Linked/Review/Unlink/Merged vocabulary. |
| EA-03 Idempotency | Duplicate-safe create/link/merge/unlink/membership commands. |
| EA-04 Catalog/Pricing | No identity-authority change; Order pricing remains separate. |
| EA-05 Financial Posting | Safe merge/unlink and financial-history consequences. |
| EA-06 Privacy/Retention | Identity evidence, link, merge, review, membership, and audit retention/privacy. |

## Recommended Option

Recommend **Option A — Federated Identity + Explicit Governed Customer Link**. It preserves separate identities, makes historical access an explicit Laravel/CRM-authoritative decision, provides a safe Limited path, and supports companies through explicit membership.

## Consequences

- Safer historical privacy, Loyalty, Complaint, and financial attribution.
- Registration can remain useful without unsafe matching.
- Identity review and organization authorization require new Phase F capabilities.
- Existing globally unique normalized phone storage cannot be assumed to eliminate legacy ambiguity and may need migration-policy review.
- Accepted Limited-account Orders need an explicit isolated Customer/context policy.
- Link, merge, unlink, projection, and audit contracts increase implementation complexity.

## Risks

| Risk | Conceptual mitigation |
| --- | --- |
| Wrong Customer linked / history disclosed | Safe deterministic rule, Laravel decision, Limited default, audit. |
| Duplicate Customer creation | Authoritative normalized search, idempotency, review uncertain no-match. |
| Multiple/shared/company phone | Never auto-select; Limited + review/membership. |
| Recycled phone / phone-change relink | Reverification, evidence-age/conflict review, no silent relink. |
| Duplicate Portal accounts / conflicting links | Cardinality checks and governed resolution. |
| Loyalty/Complaint/financial data misattribution | Link-gated projections; no candidate access or balance combination. |
| Unsafe merge / corrupted financial history | Proposal/approval, canonical survivor, immutable lineage, Finance participation. |
| Unlink erases history | Unlink changes future authorization only; preserve effective-period audit. |
| Compromised account retains access | Immediate emergency block separate from permanent unlink. |
| Identity-review backlog | CRM ownership, target SLA, Limited Order path. |
| Stale Cloud Link projection | E-07 reconciliation and authority repair; security-safe stale policy. |
| Merge/link race | E-08 conditional authoritative commit. |
| Excessive company access | Explicit scoped organization membership; no phone inference. |

## Mitigations

Prefer Limited over uncertain access; use stable opaque references, exact authoritative preconditions, minimal projections, dual governance for unlink, governed merge, scoped organization authorization, least privilege, reconciliation, and complete audit.

## Rejected Alternatives

- **Phone as universal identity:** phone is mutable, shareable, inconsistently represented, and recyclable.
- **Dual Customer master:** Cloud and Laravel could disagree about canonical identity and ownership of Orders, Loyalty, and finances.
- **Automatic merge on phone match:** same phone does not prove same Customer.
- **Let the user pick internal candidates:** exposes protected internal/customer information during ambiguity.
- **Silent relink after phone change:** can transfer historical access to another person.

## Implementation Implications for Phase F

Phase F must design link/review/merge/unlink/membership persistence, stable references, expected versions, projection repair, authorization, audit, phone normalization/migration, isolated Limited-order Customer handling, and reconciliation. This ADR does not implement or select schemas, APIs, controllers, UI, queues, or exact statuses.

## Architecture Review Questions

### Question 1

Do we approve Federated Identity—Cloud Portal Account + Laravel canonical Customer + explicit governed Identity Link—instead of phone identity or dual Customer masters?

**Recommendation:** Yes.

### Question 2

Should Laravel/CRM own the final Portal Account ↔ Customer Link decision because it grants historical business/financial/Loyalty access, while Cloud keeps only a customer-safe projection?

**Recommendation:** Yes.

### Question 3

May verified phone auto-link only with exactly one safe eligible canonical Customer and no conflicting link, security, merge, or identity condition?

**Recommendation:** Yes; verification alone never overrides ambiguity.

### Question 4

When authoritative matching proves no safe Customer exists, should Laravel create a Website/Portal-source Customer and establish the Link rather than making Cloud the Customer master?

**Recommendation:** Yes.

### Question 5

For multiple/unsafe matches, should the account remain Limited and enter Identity Review without historical Orders, Loyalty, Complaints, or financial data, while retaining safe new Portal actions?

**Recommendation:** Yes.

### Question 6

Should Link and Merge remain separate, with Merge a governed Laravel operation preserving financial, Order, Loyalty, Complaint, and audit lineage?

**Recommendation:** Yes.

### Question 7

Should permanent Unlink retain the established second approval while emergency security blocking can restrict access immediately?

**Recommendation:** Yes.

### Question 8

Should Portal/Laravel phone changes trigger verification/re-evaluation as appropriate but never silently move a Portal Account to another Customer?

**Recommendation:** Yes.

### Question 9

For Company Customers, should a human Portal Account remain a person/login identity and gain access only through explicit Organization Membership/Authorization?

**Recommendation:** Yes.

### Question 10

Should authentication/session mechanics, final statuses, idempotency, identifier formats, financial merge consequences, and retention remain deferred to EA-01, EA-02, EA-03, E-10/Phase F, EA-05, and EA-06 respectively?

**Recommendation:** Yes.

These are recommendations for Architecture Review. None is approved by this proposal.

## Traceability

- D1: registration, phone verification, linking, Limited Account, profile/phone change, history, Loyalty, Complaints, and company Portal scope.
- D2: governed identity review, merge/unlink controls, permissions, audit, and target review SLA.
- E-01/E-07/E-08: authority, reconciliation, and conditional conflict semantics.
- E-10 and EA-01 through EA-06 remain pending exactly as listed above.
