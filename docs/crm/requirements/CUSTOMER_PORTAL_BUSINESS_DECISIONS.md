# Customer Portal Business Decisions — D1 Approval

Status: **Approved**  
Scope: Business decisions for D-01 through D-15. Architecture decisions E-01 through E-10 remain Pending.

Each row records a business constraint that future architecture and implementation must preserve.

## Identity and Access

| Decision ID | Related Requirement | Decision | Rationale | Release | Architecture Dependency |
| --- | --- | --- | --- | --- | --- |
| BD-D1-001 | D-01,D-03 | First/new/suspicious-device access uses phone + OTP; subsequent recognized-device login uses phone + password. OTP also protects registration, recovery and phone changes. | Balance ownership proof with usable repeat access | MVP | E-03,E-10 |
| BD-D1-002 | D-01 | Session lasts 30 days, supports multiple devices, and reverifies sensitive actions; device management is deferred. | Reduce login friction without weakening critical actions | MVP | E-03 |
| BD-D1-003 | D-01 | PIN may later be a local convenience, never an independent security factor. | Avoid treating device PIN as identity proof | Future | E-03 |
| BD-D1-004 | D-01,D-02 | Security suspension blocks login; business restriction permits viewing but blocks orders and points. | Separate security response from commercial controls | MVP | E-03,E-05 |
| BD-D1-005 | D-01 | MVP recovery uses phone; phone-loss recovery is exceptional, administrative and audited. | Prevent automated account takeover | MVP | E-02,E-03 |
| BD-D1-006 | D-02 | Registration requires phone, name, password, and terms/privacy acceptance; email/address/zone/birth date are optional afterward. | Keep registration minimal while establishing identity and consent | MVP | E-03,E-10 |

## Customer Linking

| Decision ID | Related Requirement | Decision | Rationale | Release | Architecture Dependency |
| --- | --- | --- | --- | --- | --- |
| BD-D1-007 | D-04 | One Customer matching a verified phone links automatically; only conflicts require review. | Reuse established operational identity | MVP | E-01,E-02,E-03 |
| BD-D1-008 | D-04 | OTP proves phone ownership but does not choose among duplicate Customers; create a limited account and review case. | Prevent incorrect linkage and data disclosure | MVP | E-02,E-07 |
| BD-D1-009 | D-04 | Limited accounts may edit basic profile, add address and create a new order, but cannot view prior orders, points or complaints. | Preserve useful access without leaking history | MVP | E-02,E-04 |
| BD-D1-010 | D-02,D-04 | New portal registration creates a Laravel Customer later through the approved contract with source `website`, without automatic special receivable or discount. | Keep Laravel operationally authoritative | MVP | E-01,E-02,E-03 |

## Profile and Addresses

| Decision ID | Related Requirement | Decision | Rationale | Release | Architecture Dependency |
| --- | --- | --- | --- | --- | --- |
| BD-D1-011 | D-05 | Name changes are direct/audited; primary and backup phones are allowed and every new phone requires OTP. | Enable self-service with traceable identity changes | MVP | E-02,E-03 |
| BD-D1-012 | D-05 | Internal finance, risk classifications and confidential staff data never appear in the portal. | Enforce least privilege and business confidentiality | MVP | E-10 |
| BD-D1-013 | D-06 | Maximum five addresses, one default, using `home`, `work`, `family_home`, `other_home`, or `custom`. | Bound complexity and standardize customer labels | MVP | E-09 |
| BD-D1-014 | D-06 | Zone/description follow coverage rules; map location is optional; branch changes are allowed only among serving branches. | Prevent unserviceable fulfillment | MVP | E-09 |
| BD-D1-015 | D-06 | Recalculate delivery fee for every order and deactivate/soft-delete addresses. | Prevent stale pricing and retain history | MVP | E-07,E-09 |

## Family and Occasions

| Decision ID | Related Requirement | Decision | Rationale | Release | Architecture Dependency |
| --- | --- | --- | --- | --- | --- |
| BD-D1-016 | D-07 | Family is Post-MVP; initial fields are name, relationship and birth date, with no Customer or independent login. | Minimize sensitive scope | Post-MVP | E-02,E-10 |
| BD-D1-017 | D-07 | Allergies may later assist staff but never replace explicit order confirmation. | Avoid unsafe reliance on stored preference data | Future | E-10 |
| BD-D1-018 | D-08 | Occasions are Post-MVP, support multiple/custom types and optional year; call center sees day/month only and marketing needs separate consent. | Minimize birth data and separate service from marketing | Post-MVP | E-07,E-10 |

## Orders and Tracking

| Decision ID | Related Requirement | Decision | Rationale | Release | Architecture Dependency |
| --- | --- | --- | --- | --- | --- |
| BD-D1-019 | D-09 | Portal shows one active order when present plus only the last two completed orders; no lifetime metrics/full history. | Provide useful recency while limiting exposure | MVP | E-02,E-05,E-07 |
| BD-D1-020 | D-09 | Reorder is from those two orders only, uses current availability/prices, shows changes, copies no payment data and requires review. | Prevent stale or accidental orders | MVP | E-04,E-09 |
| BD-D1-021 | D-10 | Direct cancellation is allowed only before payment confirmation; afterward contact support and the portal never changes status directly. | Protect financial/operational state | MVP | E-05,E-08 |
| BD-D1-022 | D-10 | Before preparation, customer may request item/quantity/note/address changes; call center accepts and reprices. No ordinary change after preparation starts. | Keep production changes controlled | MVP | E-05,E-09 |

## Loyalty and Referrals

| Decision ID | Related Requirement | Decision | Rationale | Release | Architecture Dependency |
| --- | --- | --- | --- | --- | --- |
| BD-D1-023 | D-02,D-11 | Referral is recorded from a link; reward is once to referrer after first paid, delivered, successfully closed order; prohibit self/duplicate referral. | Reward verified value, not registration | MVP | E-02,E-06,E-08 |
| BD-D1-024 | D-11 | Points become final only after delivered + successfully closed; none for incomplete/cancelled orders. | Align rewards with completed value | MVP | E-05,E-08 |
| BD-D1-025 | D-11 | Redemption is automatic within rules; large/suspicious requests reserve points and require approval; Admin sees every redemption. | Combine convenience and fraud control | Post-MVP | E-06,E-07,E-08 |
| BD-D1-026 | D-11 | Rewards are invoice discount, free item, product reward, upgrade or custom reward. Reversals use Ledger; used points trigger review and may create internal negative balance. | Preserve financial traceability | Post-MVP | E-06,E-08 |

## Conversations

| Decision ID | Related Requirement | Decision | Rationale | Release | Architecture Dependency |
| --- | --- | --- | --- | --- | --- |
| BD-D1-027 | D-12 | Types are order issue, complaint, general inquiry and suggestion in one inbox with manual claim, configurable auto-assignment and supervisor reassignment. | Give support one controlled queue | MVP | E-01,E-07 |
| BD-D1-028 | D-12 | Normal alert begins after 10 minutes; active-order target is shorter/configurable; after-hours response and schedule affect SLA. | Prioritize active fulfillment while respecting staffing | MVP | E-07 |
| BD-D1-029 | D-12 | No general-chat attachments in MVP; complaint images are allowed; payment proof exists only in its dedicated flow. | Reduce file risk and preserve clear purpose | MVP | E-01,E-10 |
| BD-D1-030 | D-12 | Payment proof is private, linked to order/attempt, MIME/size/content checked (JPG/PNG/WEBP/PDF initially), audited and manually confirmed before production. | A document is evidence, not payment confirmation | MVP | E-06,E-08,E-10 |

## Notifications

| Decision ID | Related Requirement | Decision | Rationale | Release | Architecture Dependency |
| --- | --- | --- | --- | --- | --- |
| BD-D1-031 | D-13 | MVP notification channel is `in_app` only; push, WhatsApp, SMS and email are deferred. | Limit first-release integration surface | MVP | E-10 |
| BD-D1-032 | D-02,D-13 | Marketing consent is optional/unchecked, never blocks registration, is withdrawable and stores version/time/source; operational/security notices are separate. | Ensure meaningful consent | MVP | E-10 |

## Complaints

| Decision ID | Related Requirement | Decision | Rationale | Release | Architecture Dependency |
| --- | --- | --- | --- | --- | --- |
| BD-D1-033 | D-14 | Complaint may be order-linked or general, permits policy-compliant images, hides internal notes and audits conversation conversion. | Support resolution without leaking internal work | MVP | E-01,E-10 |
| BD-D1-034 | D-14 | Reopen within seven days; afterward create a new complaint linked to the previous one. | Bound reopened work while preserving continuity | MVP | E-05,E-07 |

## Ratings

| Decision ID | Related Requirement | Decision | Rationale | Release | Architecture Dependency |
| --- | --- | --- | --- | --- | --- |
| BD-D1-035 | D-15 | Three stars or less triggers follow-up; one–two stars is urgent; ask whether customer wants contact and keep follow-up traceable. | Prioritize service recovery with customer choice | MVP | E-07,E-10 |
| BD-D1-036 | D-15 | One rating edit is allowed within 24 hours and preserves the previous rating in audit history. | Permit correction without losing evidence | MVP | E-06,E-10 |
| BD-D1-037 | D-15 | Guest general rating requires verified name/phone and rate limiting; it cannot link to an unowned order or publish without consent. | Reduce abuse and protect privacy | MVP | E-02,E-10 |
