# ADR EA-01 — Portal Authentication and Sessions

## Status

**[x] Approved**

Approval Date: **2026-08-09**

Approved Decision: **Cloud-Authoritative Portal Authentication + Server-Managed Opaque Sessions + Secure HttpOnly Browser Cookies + Phone + Password Normal Login + Risk-Aware OTP Step-Up + Multi-Device Session Registry + Immediate Security Revocation**.

This proposal defines authentication/session authority and behavior only. It does not approve an identity provider, OTP vendor, framework, schema, API, cookie configuration, timeout, device algorithm, service credential, UI, or Phase F implementation.

## Context

The public Portal needs durable registration, login, recovery, step-up, multi-device sessions, and immediate security revocation without exposing local Laravel or collapsing Portal Account into Laravel Customer.

Permanent boundaries:

```text
Portal authentication authority = Cloud.
Portal Account ≠ Laravel Customer.
Authentication ≠ Identity Link ≠ Authorization ≠ Business Restriction.
Verified Phone ≠ permanent Customer identity.
Browser session ≠ Customer Link.
Session credential ≠ internal DB ID.
Knowing a Portal/Customer/Order Ref ≠ authorization.
Password never reaches Laravel.
Session revocation is server-authoritative.
```

These are permanent, binding Phase F constraints.

## Approved E-01 through E-10 Constraints

- Public Internet reaches the independent Cloud boundary; local Laravel is not public and the Sync Agent is outbound-only (E-01).
- Cloud remains available for Cloud-owned work independently of Laravel synchronization where safe (E-02/E-07).
- Security notifications use E-04 authority; authentication events do not automatically become Notifications.
- Portal Account and verified phone remain separate from Laravel Customer identity; Link and organization access are Laravel/CRM governed (E-09).
- Stable typed `PortalAccountRef`, `SessionRef`, Customer/Link refs, command/correlation refs, and access artifacts remain semantically distinct (E-10).

## Current-System Evidence

### Laravel backend

- `/api/login` currently authenticates internal `User` records by username/password, issues Sanctum personal access bearer tokens, and returns staff roles/permissions. Protected operational routes use `auth:sanctum`; logout deletes only the current access token.
- The `User` model is branch-scoped, role/permission aware, hashes passwords through Laravel casts, and represents staff—not a Cloud Portal Account.
- Laravel has session/password-reset configuration, database sessions, Sanctum stateful-domain support, cookie/CSRF middleware, and CORS configuration, but the inspected API login uses bearer tokens rather than a customer Portal session registry.
- No Portal Account model, customer registration/login, phone OTP, recovery case, step-up assurance, trusted-device registry, customer multi-session management, or Portal security suspension implementation was found.
- Public table/customer endpoints are QR/table operations, not authenticated Customer Portal account endpoints. Reusing staff Sanctum auth would not satisfy E-01/E-09.

### React Admin / Call Center

- The React admin stores the Sanctum staff bearer token, roles, permissions, and branch ID in `localStorage`, attaches the token as `Authorization: Bearer`, and clears it on logout/401.
- This is current internal Admin behavior and a known XSS exposure concern. EA-01 explicitly rejects it as the primary long-lived public Portal session model.

### Next.js website and Cloud

- The Next.js site has menu, checkout/WhatsApp, reservation, rating, and branch-local storage, but no `/account/login`, registration, phone verification, forgot-password flow, auth provider, session library, auth middleware, Portal cookie, JWT, or Portal Account persistence was found.
- No independent Cloud Integration/Auth service implementation was found. EA-01 defines target architecture only.

## Decision Drivers

- Preserve the non-public Laravel boundary.
- Enable immediate session/security revocation and independent device control.
- Prevent stale Customer Link or organization authority in browser credentials.
- Meet phone/password, new-device OTP, recovery, and approximately 30-day multi-device requirements.
- Reduce XSS bearer-token impact and address cookie CSRF/CORS correctly.
- Keep Cloud authentication availability distinct from Laravel business availability.
- Support abuse controls, auditable recovery, and privacy minimization.

## Terminology

- **Portal Account:** Cloud-authoritative human login identity.
- **Session Ref:** opaque identity of one server-managed authenticated device/client session.
- **Session Credential:** opaque browser value resolving to server-side session state.
- **Step-Up:** additional recent proof for risk or sensitive action.
- **OTP Challenge Ref:** identity of one short-lived, purpose-bound challenge; not the OTP secret.
- **Device Trust:** revocable risk input, never identity authority.
- **Security Suspension:** Cloud security state that may block login/revoke sessions.
- **Business Restriction:** Laravel/domain rule limiting actions without necessarily blocking authentication.

## Authority Model

Cloud owns Portal Account, password, verified-phone claim, OTP challenges, login state, session/device registry, revocation, assurance/step-up, recovery security state, and Security Suspension. Laravel owns Customer, business restrictions, organization membership, and business data. Laravel/CRM owns Portal↔Customer Link decisions.

## Options Considered

### Option A — Cloud Server-Managed Sessions

Cloud authentication authority, opaque server-side sessions, secure HttpOnly cookie, phone/password normal login, risk-aware OTP, and multi-device registry.

### Option B — Direct Laravel / Sanctum Portal Authentication

Expose or proxy local Laravel as the Portal credential/session authority.

### Option C — Long-Lived Stateless Browser JWT

Store meaningful long-lived bearer claims in the browser without authoritative session state.

## Detailed Comparison

| Criterion | Cloud Server Session | Laravel Direct Auth | Long-Lived JWT |
| --- | --- | --- | --- |
| E-01 compatibility | Strong | High Risk | Acceptable |
| E-09 compatibility | Strong | Weak | Weak |
| Immediate revocation | Strong | Acceptable | High Risk |
| Multi-device control | Strong | Acceptable | Weak |
| New-device OTP | Strong | Acceptable | Weak |
| Security suspension | Strong | Weak | High Risk |
| Step-up auth | Strong | Acceptable | Weak |
| Lost-phone recovery | Strong | Weak | Weak |
| Stale Customer-link safety | Strong | Weak | High Risk |
| Browser token theft impact | Strong | Acceptable | High Risk |
| Operational complexity | Acceptable | Strong | Strong |
| Scalability | Strong | Weak | Strong |
| Offline Laravel behavior | Strong | High Risk | Acceptable |
| Auditability | Strong | Acceptable | Weak |

## Recommended Architecture

```text
Browser → Cloud Portal/Auth API
→ authenticate Portal Account
→ Cloud creates server-managed session
→ browser receives opaque Secure HttpOnly credential
→ every request resolves current Account + Session + Security state
→ current Link/authorization/business state evaluated separately
```

## Portal Account Authentication Authority

Cloud is the only Portal credential/session authority. Laravel receives stable Portal/Customer refs and scoped actor context through trusted service communication when needed; it never receives the customer password or browser session as its auth authority.

## Registration

```text
Phone → purpose-bound OTP → name + password → terms/privacy acknowledgement → Portal Account
```

Email is optional. Marketing consent is optional, separate, and unchecked by default. Registration ≠ Marketing Consent. Successful phone possession verification creates a verified Portal identity claim; E-09 separately decides Customer linking.

## Phone Verification

OTP proves temporary control of a phone channel, not permanent Customer identity. Challenges are short-lived, bounded-attempt, single-purpose, one-time, rate-limited, invalidated after success, and identified by a Challenge Ref rather than the secret.

## Normal Login

A known, low-risk returning device uses Phone + Password without mandatory OTP. OTP every routine login is rejected as the default because it adds friction and provider dependency without proportional benefit.

## New Device Login

A new device uses Phone + Password followed by OTP before a normal trusted session is created. Device recognition is a risk input only and cannot authenticate by itself.

## Suspicious Login

Risk may require OTP for anomalous patterns, rapid failures, recent recovery/credential changes, security state, or safely available network/device signals. Exact risk scoring remains Phase F.

## Step-Up Authentication

Step-up is additional proof inside/before a session. A recent purpose/scoped OTP may establish stronger assurance for a bounded duration or action. Registration verification never grants permanent high assurance.

## Sensitive Actions

Architecture supports recent step-up for primary-phone change, suspicious password change, recovery completion, session/security management, high-risk Link actions, and sensitive financial/customer operations. Browsing/menu, normal authorized Order viewing, and low-risk preferences do not require OTP by default.

## Session Model

Server state conceptually records Session Ref, PortalAccountRef, creation/last activity, minimal device/client context, assurance and step-up state, idle/absolute expiration, risk, revocation time/reason, and security correlation. Mutable Customer link, loyalty, balance, membership, or business permission is not permanently embedded as session authority.

## Browser Credential

Primary web authentication uses an opaque `HttpOnly`, `Secure` cookie with appropriate SameSite, path/domain, CSRF, and origin policy. No auth secret belongs in `localStorage`. Whether `o2company.com` and the Cloud API are same-site/cross-site must be verified; wildcard credentialed CORS is prohibited. Exact settings remain Phase F.

## Session Lifetime

The business target is an approximately 30-day remembered/rolling multi-device horizon, not an unrevocable 30-day token. Security state, inactivity, absolute lifetime, password/phone/recovery events, and risk may end it earlier.

## Idle / Absolute Expiration

Both idle and absolute/max expiration are required so active sessions may remain convenient but cannot renew forever. Exact values beyond the 30-day target remain security tuning.

## Multi-Device Sessions and Management

Each phone/laptop/tablet has an independent Session Ref, device context, activity, expiry, assurance, and revocation. Portal architecture supports viewing sessions, revoking one, revoking others, and logout-all. A stolen session can be revoked without relying only on password change.

## Logout / Logout All

Logout invalidates the current Cloud server session and clears the credential; cookie deletion alone is insufficient. Logout-all revokes all Portal Account sessions. Security actions take effect at Cloud authority without waiting for Laravel synchronization.

## Password Storage and Policy

Cloud stores only industry-standard adaptive password hashes; never plaintext, reversible values, or raw password logs, and never duplicates passwords into Laravel. Policy supports reasonable minimum strength, compromised-password protection where available, rate limits, and safe reset without arbitrary periodic expiration. Exact parameters remain Phase F.

## Forgot Password and Recovery

Normal recovery uses Phone OTP, recovery checks, new password, and revocation of all prior sessions; only the freshly verified recovery context may continue. Generic responses reduce account/value enumeration.

Recovery OTP is purpose-distinct from login step-up. Lost-phone recovery has no weak automatic fallback: it enters governed Identity/Security Review with authorized reviewer/approver where required and records Portal ref, case, evidence, reason, old/new phone, session revocations, time, and outcome—never OTP secrets.

## Phone Change

An authenticated user completes recent step-up and verifies the new phone. Cloud updates the claim, applies session re-evaluation/revocation policy, and triggers E-09 identity-link re-evaluation. It never silently relinks another Customer.

## Password Change

Ordinary authenticated change requires current credential and/or recent step-up according to risk. Recommended default: current elevated session may continue, other sessions are revoked; suspicious/compromise conditions revoke all. Exact policy remains Phase F.

## Security Suspension / Business Restriction

Cloud Security Suspension may reject login and immediately revoke sessions. Laravel Business Restriction may permit login/read while blocking selected operations. It is not an identity mismatch or automatic logout.

## Authentication vs Identity Link vs Authorization

Authentication answers which Portal Account controls the session. Identity Link identifies an authorized Customer/Organization context. Authorization decides permitted access. Business rules decide whether the operation is currently allowed. Each is independently evaluated.

## Customer Link and Organization Changes

Protected requests use current Link/membership/authorization state. Revoking Customer Link or organization membership removes that access from an active session without requiring the human authentication itself to disappear. Limited/Review Accounts may remain authenticated while protected history stays unavailable.

## Browser / Laravel and Service Boundaries

```text
Browser credentials/session → Cloud only
Cloud/Integration → trusted service contract → Agent/Laravel
```

Customer tokens never double as Sync Agent credentials. Service authentication, signing, rotation, and transport mechanisms remain Phase F.

## Rate Limiting and OTP Security

Registration, login, OTP request/verify, recovery, and phone change require layered limits by appropriate account/phone/network/device/risk context, bounded challenges, replay prevention, audit, temporary escalation, and no easily weaponized permanent lockout. Exact thresholds remain Phase F.

## OTP Purpose and Device Trust

Purposes include registration, new-device login, sensitive action, phone change, and recovery. Proof for one purpose cannot silently authorize another. Known-device state is revocable and never substitutes for credentials/OTP where required; copied device material cannot authenticate forever.

## CSRF / CORS

Cookie-authenticated state changes require CSRF defenses appropriate to the chosen topology. `HttpOnly` reduces direct credential theft but does not eliminate XSS. Credentialed origins are explicit; exact domains, cookie scope, SameSite, preflight, proxy, and CSRF implementation require deployment verification.

## Cloud / Laravel / Agent Outages

- Cloud Auth unavailable: new login unavailable; never expose Laravel or invent offline browser auth. Existing sessions continue only when Cloud can safely validate them.
- Laravel unavailable: Cloud may authenticate/manage sessions and Cloud security, but cannot invent Laravel Customer/Link/business authority; safe Limited behavior remains.
- Agent unavailable: Portal sessions remain valid; cross-boundary changes queue/reconcile under E-02/E-07.

## Session Audit and Security Notifications

Audit registration/verification/login outcomes, challenge/step-up, recovery, credential/phone changes, session creation/activity/revocation, logout-all, suspension, actor, reason, and correlation with minimized metadata. New-device login, password/phone change, recovery, and logout-all may trigger E-04 Security Notifications; delivery remains E-04.

## Privacy

Collect least device/network data, limit fingerprinting, bind it to security purpose, never log passwords/OTP, restrict recovery evidence, and defer retention/redaction to EA-06.

## Authentication Authority Matrix

| Concern | Authority |
| --- | --- |
| Portal Account/password/verified phone | Cloud |
| OTP challenge and browser session | Cloud |
| Device/session registry and Security Suspension | Cloud |
| Laravel Customer/business restriction | Laravel |
| Portal↔Customer Link | Laravel/CRM |
| Organization membership | Laravel/CRM |

## Authentication vs Authorization Matrix

| Scenario | Authentication | Identity Link | Business Authorization |
| --- | --- | --- | --- |
| Logged in Limited Account | Valid | None/unresolved | Safe Limited actions only |
| Linked individual | Valid | Current governed link | Current authorized Customer scope |
| Security suspended | Blocked/revoked | Unchanged | No session access |
| Business restricted | May remain valid | May remain | Selected actions blocked |
| Organization member | Valid | Individual identity separate | Current scoped membership only |
| Membership revoked | Valid | Individual context unchanged | Organization access removed |
| Customer link revoked | Valid | Removed | Customer history removed |

## Session Scenario Matrix

| Scenario | Password | OTP | Session Result |
| --- | ---: | ---: | --- |
| Registration | Create | Required | New verified Portal session/account |
| Known device normal login | Required | Normally no | Standard session |
| New device login | Required | Required | Session after step-up |
| Suspicious login | Required | Required/risk | Session or deny/review |
| Sensitive action | Session/current credential as policy | Recent required | Temporary scoped assurance |
| Forgot password | Replaced | Recovery required | Prior sessions revoked |
| Lost phone | Not sufficient | Unavailable | Governed recovery; no automatic session |
| Phone change | Session/current auth | New-phone required | Re-evaluate/revoke sessions per policy |
| Password change | Current credential/step-up | Risk-based | Current may remain; others revoked by default |
| Security suspension | Irrelevant | Irrelevant | Login blocked; sessions revoked |

## Session Lifecycle

```text
Unauthenticated → Primary Credential Accepted
→ Step-Up Required where applicable → Authenticated Session
→ Active → Idle / Expired / Revoked
```

These are conceptual states; EA-02 owns final shared vocabulary.

## Impact on Other Decisions

| Decision | EA-01 Impact |
| --- | --- |
| EA-02 Canonical Status Dictionary | Final security/session/recovery names where shared vocabulary is needed. |
| EA-03 Idempotency | Duplicate-safe registration/recovery/change commands and replay behavior. |
| EA-04 Catalog/Pricing | No authentication authority change. |
| EA-05 Financial Posting | Sensitive financial actions may require EA-01 assurance. |
| EA-06 Privacy/Retention | Auth/session/device/recovery audit retention and privacy. |

## Approved Option

Approved: **Option A — Cloud-Authoritative Portal Authentication + Server-Managed Opaque Sessions + Secure HttpOnly Browser Cookies + Phone + Password Normal Login + Risk-Aware OTP Step-Up + Multi-Device Session Registry + Immediate Security Revocation**.

## Consequences

- Strong revocation, device control, suspension, step-up, and stale-link safety.
- Approximately 30-day convenience remains compatible with immediate security response.
- Cloud session availability is independent of Laravel while Laravel authority is never invented.
- Cookie topology requires careful CSRF/CORS/domain configuration.
- Cloud Auth/session storage becomes critical infrastructure requiring availability, backup, monitoring, and recovery.
- Phase F must implement new Portal identity/session/recovery capabilities; current staff auth is not reused as authority.

## Risks

| Risk | Conceptual mitigation |
| --- | --- |
| Password copied to/executed by Laravel | Cloud-only credential authority; service context contains no password. |
| Laravel publicly exposed | Browser→Cloud only; outbound Agent boundary. |
| JWT/localStorage bearer theft | Opaque HttpOnly credential and server revocation. |
| Compromised session cannot revoke | Per-session and account-wide registry revocation. |
| Stale Link/membership claims | Re-evaluate current authorization; do not embed permanent business authority. |
| Suspension latency | Cloud-authoritative immediate denial/revocation. |
| OTP spam/brute force/replay | Purpose binding, limits, expiry, one-time use, abuse monitoring. |
| Credential stuffing/enumeration | Layered limits, generic responses, risk challenge, audit. |
| New-device/trust bypass | Password plus OTP; device trust is revocable risk input. |
| Recovery/lost-phone takeover | Purpose-specific OTP or governed audited exception; revoke old sessions. |
| Phone change relinks Customer | Step-up/new-phone verify plus E-09 review; no silent relink. |
| Business/security state confusion | Separate authorities and explicit checks. |
| CSRF/CORS error | Verified topology, explicit origins, CSRF controls, no wildcard credentials. |
| Session store loss/outage | Durable HA storage, backups, fail-safe validation, monitoring in Phase F. |
| Laravel outage blocks login | Cloud owns auth; only Laravel-owned access degrades safely. |
| Excessive device metadata | Least data/purpose limitation and EA-06 retention. |

## Mitigations

Cloud-only adaptive password hashing, opaque revocable sessions, current authorization checks, purpose-bound OTP, layered abuse control, step-up freshness, session/device management, immediate suspension, safe recovery, explicit browser/service boundaries, CSRF/CORS verification, audit, notifications, availability engineering, and privacy minimization.

## Rejected Alternatives

- **Direct public Laravel Auth:** violates E-01 and makes local Laravel an Internet credential boundary.
- **Long-lived stateless browser JWT:** weakens immediate revocation, device management, suspension, step-up, and stale-claim safety.
- **OTP every login:** adds unnecessary friction/provider dependency; retain OTP for registration, risk, step-up, and recovery.
- **Phone-only passwordless normal login:** conflicts with approved Phone + Password direction and increases OTP dependency.
- **Customer ID embedded as permanent session authority:** E-09 Link/membership can change while session remains authenticated.
- **Forward password to Laravel:** creates a second credential authority and expands secret exposure.

## Implementation Implications for Phase F

Phase F must select Cloud auth technology, adaptive hashing configuration, OTP provider/challenge controls, session store/HA, opaque credential and cookie topology, CSRF/CORS/origin policy, timeouts, device-risk model, recovery governance, service authentication, schemas/APIs/UI, audit/notifications, monitoring, backup, and incident controls. EA-02/EA-03/EA-06 retain their listed decisions.

## Architecture Review Decisions

### Question 1

Do we approve Cloud-Authoritative Portal Authentication + Server-Managed Opaque Sessions instead of direct public Laravel authentication or long-lived stateless browser JWTs?

**Decision:** Approved.

**Rationale:** Cloud authority preserves E-01/E-09 and enables revocable session security.

**Deferred / Follow-up:** Exact technology and persistence remain Phase F.

### Question 2

Should the primary Portal web session use a secure HttpOnly browser credential backed by server-side Cloud session state, with exact cookie/domain/CSRF/CORS configuration deferred to Phase F?

**Decision:** Approved.

**Rationale:** An opaque HttpOnly credential reduces bearer-token exposure while Cloud retains current session state.

**Deferred / Follow-up:** Cookie, SameSite, CSRF, CORS, domain, and path details remain Phase F.

### Question 3

Should normal returning login use Phone + Password while OTP is required for initial registration, new/suspicious devices, selected sensitive actions, and recovery rather than every routine login?

**Decision:** Approved.

**Rationale:** Phone + Password balances routine usability while purpose-bound OTP protects registration, risk, recovery, and sensitive actions.

**Deferred / Follow-up:** OTP provider, risk rules, and exact challenges remain Phase F.

### Question 4

Should the Portal support multiple independent device sessions with approximately the approved 30-day remembered-session horizon, while retaining idle/absolute expiration, per-session revocation, logout-all, and immediate security revocation?

**Decision:** Approved.

**Rationale:** Independent server sessions meet multi-device convenience without creating an unrevocable 30-day token.

**Deferred / Follow-up:** Idle/absolute timeout tuning and UI remain Phase F.

### Question 5

Should sensitive actions support recent OTP-based step-up authentication, with assurance freshness/scope enforced separately from the ordinary logged-in session?

**Decision:** Approved.

**Rationale:** Bounded recent assurance prevents registration-time verification from authorizing sensitive actions forever.

**Deferred / Follow-up:** Exact freshness, scope, and action mapping remain Phase F.

### Question 6

Should Security Suspension block login and revoke active sessions immediately, while Business Restriction remains a separate Laravel/business authorization concern that may still permit login?

**Decision:** Approved.

**Rationale:** Security compromise requires immediate Cloud action, while business eligibility remains a distinct Laravel concern.

**Deferred / Follow-up:** Shared state vocabulary remains EA-02; enforcement implementation remains Phase F.

### Question 7

Should normal password recovery use verified phone OTP and revoke existing sessions according to recovery policy, while lost-phone recovery requires a governed audited internal exception rather than weak fallback?

**Decision:** Approved.

**Rationale:** Recovery replaces credentials and therefore requires strong proof, session revocation, and governed lost-phone handling.

**Deferred / Follow-up:** Exact evidence, roles, provider controls, and workflows remain Phase F/EA-06.

### Question 8

Should Portal phone changes require recent step-up plus new-phone verification and trigger E-09 Link re-evaluation, while never silently relinking another Laravel Customer?

**Decision:** Approved.

**Rationale:** A major login/recovery-factor change requires fresh proof without transferring Customer history by phone coincidence.

**Deferred / Follow-up:** Session effects remain Phase F; Link consequences remain E-09.

### Question 9

Should Laravel never receive the Portal password or browser session as its authentication authority, preserving browser→Cloud auth and separate trusted Cloud/Agent/Laravel service communication?

**Decision:** Approved.

**Rationale:** Portal customer secrets must remain at the Cloud boundary; machine trust is a separate security contract.

**Deferred / Follow-up:** Service credential/signature design remains Phase F.

### Question 10

Should exact OTP provider/timing/limits, password parameters, cookie names/domains/SameSite, timeout tuning, device algorithm, service-token implementation, schema, APIs, and UI remain Phase F/EA-06 work while EA-01 approves behavior?

**Decision:** Approved.

**Rationale:** EA-01 fixes behavioral authority while avoiding premature coupling to security providers and deployment topology.

**Deferred / Follow-up:** Listed implementation details remain Phase F; privacy/retention remains EA-06 and shared vocabulary remains EA-02.

All ten Architecture Review Decisions above were explicitly approved on **2026-08-09**. EA-02 through EA-06 and Phase F remain pending; this approval does not begin EA-02 or implementation.

## Traceability

- D1: registration, phone verification, Phone + Password login, new-device OTP, 30-day multi-device sessions, recovery, phone/password change, security state, and account routes.
- D2: governed lost-phone recovery, security permissions, audit, identity review, and operational visibility.
- E-01 through E-10 remain approved and unchanged.
- EA-02 through EA-06 remain pending. Phase F remains not started.
