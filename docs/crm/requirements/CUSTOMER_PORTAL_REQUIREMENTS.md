# Customer Portal Requirements — D1

Status: **Business Approved — Awaiting Architecture Decisions**.

Requirements D-01 through D-15 are business approved. Implementation remains prohibited until the required E decisions are approved. This document specifies requirements only and makes no architecture or schema decision.

## 1. Executive Summary

The portal gives guests and verified O2 customers a mobile-first Arabic self-service experience for identity, profiles, addresses, orders, rewards, support, and feedback. Laravel remains the operational and financial authority; the portal must not claim an order, payment, or reward is final before Laravel confirms it. Admin CRM receives only the customer context, messages, complaints, website orders, and consent data staff are authorized to use.

MVP covers phone-based access, progressive registration, safe customer linking, profiles, addresses, limited order history/tracking, reward visibility/redemption requests, messages, in-app notifications, complaints, and ratings. Independent family login, advanced attachments/chatbots, voice/video, external notification channels, payment-provider behavior, and integration implementation are deferred. Current constraints are: no portal identity or website orders; WhatsApp checkout; ratings-only website database; fragmented phones; balance-only loyalty; no durable synchronization or unified timeline/statuses; and duplicated catalog/pricing data.

### Business-approved baseline

- **Identity and access:** First login and every new/suspicious device use phone + OTP; the customer then creates a password and later signs in with phone + password. OTP is also required for registration, recovery, and phone changes. PIN may later be a local convenience only. Sessions last 30 days and may exist on multiple devices; device management is outside MVP and sensitive actions require reverification. Security suspension blocks login; business restriction permits account viewing but blocks ordering and points. MVP recovery uses the phone; loss of phone requires exceptional audited administration.
- **Registration and linking:** Required registration data is phone, name, password, and versioned terms/privacy acceptance. Email, address, zone, and birth date are optional afterward. One verified matching Laravel Customer links automatically; conflicts create a limited account and administrative review. A limited account may edit its basic profile, add an address, and create a new order, but cannot view prior orders, points, or complaints. A new account creates a Laravel Customer later through the approved integration contract, records source `website`, and receives no automatic special receivable, discount, or referral points.
- **Referral:** Capture a valid referral link, prohibit self/duplicate/multiple rewards, and award the referrer once only after the referred customer's first paid, delivered, successfully closed order. Any future new-customer discount belongs to Loyalty Rules.
- **Profile and addresses:** Name edits are direct and audited. A primary and backup phone are allowed; every new phone needs OTP. A customer may keep at most five addresses, with one default and types `home`, `work`, `family_home`, `other_home`, or `custom`. Zone/description follow coverage rules; map location is optional. The suggested branch may be changed only to another serving branch. Delivery fees are recalculated per order and deletion deactivates/soft-deletes.
- **Family and occasions:** Both are Post-MVP. Initial family data is name, relationship, and birth date; no financial Customer or login is created. Allergies may later assist staff but never replace order confirmation. Occasions support multiple/custom types and optional year. Call-center staff see only day/month; birth year is restricted or omitted by default. Marketing requires independent consent.
- **Orders and tracking:** Show one active order when present plus the last two completed orders only. Do not expose lifetime count/spend/averages or full history; Admin retains full history. Reorder is limited to those two orders, uses current availability/pricing, shows price changes, copies no payment data, and requires review. Direct cancellation is allowed only before payment confirmation; afterward the customer contacts support. A modification request may cover items, quantities, notes, or address before preparation, requires call-center acceptance and repricing, and never directly changes status.
- **Loyalty:** Points become final only after delivered + successfully closed. Redemption is automatic within configured rules/limits; large or suspicious requests require approval and may reserve points. Reward types are `invoice_discount`, `free_item`, `product_reward`, `upgrade`, and `custom_reward`. Cancellation/refund creates ledger reversal; used points trigger financial/administrative review and may yield an internal negative balance with a clear customer-facing settlement description.
- **Conversations and payment proof:** Conversation types are `order_issue`, `complaint`, `general_inquiry`, and `suggestion`. A unified inbox supports manual claim, configurable automatic assignment, and supervisor reassignment. Normal conversations alert after 10 minutes; active orders use a shorter configurable target; after-hours messaging respects the work schedule. General-chat attachments are excluded from MVP. Complaint images are allowed. Payment proof is limited to its dedicated flow, privately stored, linked to an order/payment attempt, MIME/size/content checked (initially JPG/PNG/WEBP/PDF), fully audited, and requires authorized staff confirmation before production.
- **Notifications and consent:** MVP channel is `in_app` only; push, WhatsApp, SMS, and email are deferred. Marketing consent is an unchecked optional registration choice, may be withdrawn, never blocks registration, is distinct from operational/security messaging, and records version, time, and source.
- **Complaints and ratings:** Complaint images follow file policy. A complaint may reopen within seven days; afterward a new complaint links to the previous one. Internal notes remain hidden and conversation conversion is audited. Ratings of 3 stars or less create follow-up; 1–2 stars are urgent and ask whether contact is desired. A rating may be edited once within 24 hours while preserving audit history. A verified-name-and-phone guest may submit a rate-limited general rating, never link it to an unowned order, and never publish it without consent.

## 2. Actors

| Actor | Can view | Can do | Cannot do |
| --- | --- | --- | --- |
| Guest | Public portal and approved public content | Start registration/login; submit permitted public rating | View customer data, orders, rewards, or support history |
| Portal Customer | Own permitted profile, orders, rewards, messages, complaints, notifications | Maintain own data and submit requests | Change operational status, financial records, or another customer |
| Family Account Owner | Own account and optional family records | Add/edit/delete optional family data with consent | Create financial customers or independent family logins in MVP |
| Customer Support Agent | Assigned/authorized customer context, messages and complaints | Respond, classify, escalate, record follow-up | View restricted finance or private family data without permission |
| CRM Administrator | Authorized customer identity, linking, consent and operational records | Review matches and administer permitted records | Bypass audit, expose secrets, or perform accounting without permission |
| System Integration | Minimum contract data and synchronization metadata | Validate, exchange, retry and reconcile authorized events | Make business decisions, expose secrets, or silently overwrite authority |

## 3. Customer Portal Sitemap

| Route | Access | Verification | Notification deep link |
| --- | --- | --- | --- |
| `/account/login` | Public | No | No |
| `/account/register` | Public | Completed during flow | No |
| `/account` | Protected | Verified account | Yes |
| `/account/profile` | Protected | Verified for sensitive changes | Yes |
| `/account/addresses` | Protected | Verified | Yes |
| `/account/family` | Protected | Verified | Yes |
| `/account/occasions` | Protected | Verified | Yes |
| `/account/orders` | Protected | Verified and safely linked | Yes |
| `/account/orders/[id]` | Protected; opaque reference | Verified and owns order | Yes |
| `/account/rewards` | Protected | Verified and linked | Yes |
| `/account/messages` | Protected | Verified | Yes |
| `/account/notifications` | Protected | Verified | Yes |
| `/account/support` | Protected | Verified | Yes |
| `/account/settings` | Protected | Verified; reverify critical actions | Yes |

Deep links must authenticate first, preserve a safe destination, authorize the referenced resource, and never reveal data in the URL.

## 4. Detailed Requirements

### D-01 — Login

**Requirement ID:** D-01  
**Requirement Name:** Phone-based login  
**Priority:** P0 / MVP  
**Actors:** Guest, Portal Customer, System Integration  
**Business Goal:** Secure access without exposing Laravel Customer IDs.  
**Current State:** No website login, registration, account, or Portal Account.  
**Target Requirement:** Use phone + OTP for first/new/suspicious-device access, then establish a 30-day secure multi-device session after password creation; subsequent recognized-device login uses phone + password.  
**Preconditions:** Account is eligible; verification service is available; terms remain applicable.  
**Trigger:** Guest submits a phone at `/account/login`.  
**Primary Flow:** Normalize phone → request code → enter code → validate account status → establish session → record login → redirect safely.  
**Alternative Flows:** Unknown phone offers registration without revealing customer classification; recovery is offered for an already-linked account.  
**Failure Scenarios:** Expired/incorrect code, resend/attempt limit, suspended/closed account, service failure, replay, session creation failure.  
**Business Rules:** Login belongs to Portal Account; OTP applies to registration, recovery, phone change, new device, and suspicious activity; sensitive actions reverify; PIN is only a future local convenience; security suspension blocks login while business restriction permits viewing but blocks orders/points.  
**Validation Rules:** Palestinian formats normalized; generic responses; code lifetime, attempts, resend interval and lock duration are configurable.  
**Permissions:** Only account owner receives a session; integration receives minimum verification data.  
**Required Data:** Normalized phone, verification challenge, account status, session/device metadata.  
**Data Source:** Portal Account Pending E-03; customer link Pending E-02.  
**Data Written:** Challenge audit, successful/failed attempt metadata, session, last login; never the plaintext OTP in logs.  
**Notifications:** Verification and security events through a channel Pending E-10.  
**Audit Requirements:** Request, resend, failure, lock, login, single/all-device logout; redact code and sensitive identifiers.  
**Privacy Requirements:** Minimize phone exposure and prevent account enumeration.  
**Dependencies:** D-03, D-04, E-02, E-03, E-10.  
**Architecture Decisions Required:** OTP provider/lifetime/attempts, session implementation, device recognition, password storage/recovery, and suspicious-activity signals.  
**Acceptance Criteria:** Verified owner receives a secure session; invalid/suspended cases fail generically; logout revokes required sessions; no Customer ID or OTP leaks.  
**Out of Scope:** Final provider, password implementation, social login.  
**Open Questions:** OTP provider/limits and technical session/device controls remain Pending Architecture Decisions; recovery operations after phone loss remain Pending Operational Configuration.

### D-02 — Account Registration

**Requirement ID:** D-02  
**Requirement Name:** Progressive account creation  
**Priority:** P0 / MVP  
**Actors:** Guest, Portal Customer, CRM Administrator, System Integration  
**Business Goal:** Create a usable portal identity without duplicate customers or excessive onboarding.  
**Current State:** No Portal Account; Laravel customers may already exist.  
**Target Requirement:** Register with verified phone, required name/password/versioned terms and privacy acceptance; collect email, address, zone, and birth date optionally afterward.  
**Preconditions:** Phone passes normalization and anti-abuse controls.  
**Trigger:** Guest chooses registration or continues from unknown-phone login.  
**Primary Flow:** Submit phone → verify → detect account/customer candidates → accept policies → create/link limited portal account → record source `website`, creation and last-login times.  
**Alternative Flows:** New customer remains pending approved customer-creation flow; existing customer enters safe linking; ambiguous match enters review.  
**Failure Scenarios:** Duplicate Portal Account, policy not accepted, verification failure, ambiguous match, partial integration failure.  
**Business Rules:** Proposed states are `pending_verification`, `active`, `suspended`, `closed`; they are requirements, not schema. Record source `website`; create the Laravel Customer through the later approved contract without automatic special receivable/discount. Referral is awarded only once to the referrer after the first paid, delivered, successfully closed order.  
**Validation Rules:** Unique normalized phone within chosen identity contract; required policy versions and timestamps.  
**Permissions:** Guest creates only own account; administrators may review but not impersonate silently.  
**Required Data:** Phone, optional name, policy versions/consent, source, timestamps, status.  
**Data Source:** Portal Account Pending E-03; operational customer Laravel.  
**Data Written:** Portal identity and consent evidence; Laravel customer only through approved later flow.  
**Notifications:** Registration/security confirmation without sensitive data.  
**Audit Requirements:** Creation, policy acceptance, link outcome, status changes.  
**Privacy Requirements:** Progressive collection, purpose limitation, explicit consent.  
**Dependencies:** D-03, D-04, E-02, E-03, E-10.  
**Architecture Decisions Required:** Identity/linking/account ownership and consent retention.  
**Acceptance Criteria:** One portal account per normalized identity; existing customers are not duplicated; minimal registration works; consent is evidenced.  
**Out of Scope:** Full profile, financial account creation, final schema.  
**Open Questions:** Customer-creation transport/transaction boundary and referral ledger mechanism remain Pending Architecture Decisions.

### D-03 — Phone Verification

**Requirement ID:** D-03  
**Requirement Name:** Phone ownership verification  
**Priority:** P0 / MVP  
**Actors:** Guest, Portal Customer, System Integration  
**Business Goal:** Prove phone control while resisting guessing, replay and enumeration.  
**Current State:** Phone is fragmented across Laravel fields; no website OTP.  
**Target Requirement:** Normalize expected Palestinian formats, issue a short-lived one-time challenge, rate-limit sending/entry and temporarily lock abuse.  
**Preconditions:** Valid phone syntax and permitted request context.  
**Trigger:** Login, registration, recovery, or sensitive phone change.  
**Primary Flow:** Normalize → generic acknowledgement → deliver code → verify once → invalidate challenge.  
**Alternative Flows:** Resend replaces/invalidates prior challenge; support recovery follows an approved secure path.  
**Failure Scenarios:** Invalid format, expired/replayed code, too many sends/attempts, provider outage, delayed code.  
**Business Rules:** Never reveal VIP/existing-customer status; limits apply per phone/device/network risk signals without unsafe lockout.  
**Validation Rules:** Canonical normalization contract Pending E-02; configurable lifetime, attempts, resend and lock thresholds.  
**Permissions:** Only challenge owner can complete it; staff cannot retrieve codes.  
**Required Data:** Normalized phone, challenge reference/hash, expiry, counters, risk metadata.  
**Data Source:** Verification service Pending E-03/E-10.  
**Data Written:** Hashed/opaque challenge and redacted audit metadata.  
**Notifications:** OTP through an unselected channel/provider.  
**Audit Requirements:** Sensitive attempts and locks without OTP content.  
**Privacy Requirements:** Mask phone in UI/logs and retain attempt data only per E-10.  
**Dependencies:** E-02, E-03, E-10.  
**Architecture Decisions Required:** Provider, normalization, limits, delivery channel and retention.  
**Acceptance Criteria:** Supported formats normalize consistently; replay/expired/excess attempts fail; no OTP appears in logs; responses prevent enumeration.  
**Out of Scope:** Selecting or integrating a provider.  
**Open Questions:** Exact supported prefixes/provider are Pending Architecture Decisions; attempts, expiry, lock duration and resend interval are Pending Operational Configuration.

### D-04 — Existing Customer Linking

**Requirement ID:** D-04  
**Requirement Name:** Safe Portal Account–Customer linking  
**Priority:** P0 / MVP  
**Actors:** Portal Customer, CRM Administrator, System Integration  
**Business Goal:** Preserve one operational customer record and prevent account takeover.  
**Current State:** Laravel has customers/multiple phone fields but no UUID, mapping, or Portal Account.  
**Target Requirement:** Link only after phone ownership proof and deterministic resolution; ambiguous matches require authorized review.  
**Preconditions:** Verified Portal Account and normalized phone.  
**Trigger:** Registration completion, login recovery, or verified phone addition.  
**Primary Flow:** Resolve candidates → one safe match → verify ownership/link constraints → create auditable link → expose authorized history.  
**Alternative Flows:** No match creates a new Customer later through the approved contract; multiple matches create a limited account and review without disclosure; the limited account may edit its basic profile, add an address, and create a new order but cannot see prior history/points/complaints; multiple phones identify a verified primary; existing link invokes secure recovery.  
**Failure Scenarios:** Ambiguity, conflicting existing link, stale mapping, integration outage, attempted takeover.  
**Business Rules:** One verified match links automatically; OTP proves phone ownership but cannot choose among duplicates. No automatic ambiguous link or special receivable; customer merge is administrative, privileged and outside portal scope.  
**Validation Rules:** Verified normalized phone plus chosen E-02 identity invariant; one active ownership link per policy.  
**Permissions:** Customer sees only own result; CRM Administrator reviews candidates; support sees no unrelated candidate data.  
**Required Data:** Opaque portal identity, verified phone, candidate references, link/review status and evidence.  
**Data Source:** Laravel customer; Portal identity Pending E-02/E-03.  
**Data Written:** Link or review case; new Customer only under approved later process.  
**Notifications:** Generic link/recovery/review outcome.  
**Audit Requirements:** Candidate count, reviewer, reason, before/after link; redact unrelated PII.  
**Privacy Requirements:** Never expose matched customer records; use data minimization.  
**Dependencies:** D-02, D-03, E-01, E-02, E-03, E-07.  
**Architecture Decisions Required:** UUID/mapping/phone role; limited-account behavior; customer-creation authority.  
**Acceptance Criteria:** Zero/one/many/existing-link scenarios behave safely; no duplicate or takeover; ambiguous records enter review.  
**Out of Scope:** Customer merge and final mapping schema.  
**Open Questions:** Identity/mapping implementation remains Pending Architecture Decisions; reviewer assignment and review SLA remain Pending Operational Configuration.

### D-05 — Profile

**Requirement ID:** D-05  
**Requirement Name:** Customer profile management  
**Priority:** P0 / MVP  
**Actors:** Portal Customer, CRM Administrator, System Integration  
**Business Goal:** Let customers maintain appropriate data without exposing internal CRM/finance fields.  
**Current State:** Laravel customer is rich; portal profile is absent.  
**Target Requirement:** Show/edit basic name directly; support verified primary and backup phones; optionally collect email, preferred branch/language, birth date, justified gender, food preferences/allergies and preferred channel.  
**Preconditions:** Authenticated account; safe link where Laravel data is required.  
**Trigger:** Customer opens/saves profile.  
**Primary Flow:** Load authorized projection → edit allowed fields → validate/reverify sensitive changes → synchronize under approved contract → confirm.  
**Alternative Flows:** Unlinked account edits cloud-only permitted fields; phone change starts reverification; conflicts enter review.  
**Failure Scenarios:** Invalid/stale update, sync outage, unauthorized field, duplicate phone.  
**Business Rules:** Name edits apply directly and every change is audited; each new/changed phone requires OTP; field authority/cloud-vs-Laravel placement remains Pending E; account date is read-only and internal/financial/risk fields remain hidden.  
**Validation Rules:** Names/phone/email/branch/language formats; allergy free text treated sensitive.  
**Permissions:** Customer allowed fields; CRM Administrator governed correction; support read minimum; internal risk/credit/notes/accounting always hidden.  
**Required Data:** Basic and optional fields plus consent/version metadata.  
**Data Source:** Operational fields Laravel; portal-only fields Pending E Decision.  
**Data Written:** Valid changes and synchronization/audit metadata.  
**Notifications:** Security notice for phone/critical changes.  
**Audit Requirements:** Field-level before/after for sensitive changes, actor and source.  
**Privacy Requirements:** Optional fields need clear purpose; allergies and birth data restricted; deletion/correction supported by policy.  
**Dependencies:** D-03, D-04, E-02, E-03, E-07, E-10.  
**Architecture Decisions Required:** Field ownership, synchronization, reverification and consent.  
**Acceptance Criteria:** Only allowed fields appear/change; sensitive changes reverify; internal fields never appear; outages are explicit.  
**Out of Scope:** Credit/risk/accounting/staff notes.  
**Open Questions:** Field authority/synchronization remain Pending Architecture Decisions; optional sensitive-field purpose/retention remain Pending Privacy Policy.

### D-06 — Addresses

**Requirement ID:** D-06  
**Requirement Name:** Delivery address book  
**Priority:** P0 / MVP  
**Actors:** Portal Customer, System Integration, CRM Administrator  
**Business Goal:** Maintain reusable, serviceable delivery destinations.  
**Current State:** Laravel has customer addresses; website has duplicated delivery configuration.  
**Target Requirement:** Up to five addresses typed `home`, `work`, `family_home`, `other_home`, or `custom`, with one default, required zone/description per coverage rules, optional map location, recipient details, courier note and serving branch.  
**Preconditions:** Verified account; current delivery coverage data available or staleness shown.  
**Trigger:** Add/edit/select/deactivate address.  
**Primary Flow:** Enter address → validate recipient/zone → resolve serving branch/current fee → save → optionally set default.  
**Alternative Flows:** Coverage unknown during outage is clearly pending; changed fee is recalculated at order review; deactivate retains historical snapshots.  
**Failure Scenarios:** Outside coverage, invalid recipient phone, stale zone/fee, branch unavailable, sync conflict.  
**Business Rules:** One default and maximum five; system suggests branch and customer may select only another branch serving the address; delivery fee is recalculated for every order; historical addresses use soft delete/deactivation.  
**Validation Rules:** Required structured/detail fields, normalized recipient phone, coordinates bounds when supplied, current coverage validation.  
**Permissions:** Customer owns addresses; authorized CRM staff may assist with audit; courier sees delivery minimum only.  
**Required Data:** Label/default, zone/details/landmark, recipient, note, optional coordinates, branch, active state.  
**Data Source:** Address authority Pending E; coverage/menu/fees Pending E-09.  
**Data Written:** Address and verification/sync metadata; order later stores an approved snapshot.  
**Notifications:** Warn on coverage/fee changes during order review.  
**Audit Requirements:** Create/edit/default/deactivate and coverage result.  
**Privacy Requirements:** Location and recipient PII restricted and retained per E-10.  
**Dependencies:** D-04, E-07, E-09, E-10.  
**Architecture Decisions Required:** Address authority, coverage contract, coordinates provider and retention.  
**Acceptance Criteria:** Multiple addresses and one default work; invalid/out-of-zone addresses cannot be used; current fee is revalidated; history is preserved.  
**Out of Scope:** Map-provider selection and delivery pricing implementation.  
**Open Questions:** Coverage/source contract remains Pending Architecture Decisions; field rules, manual exceptions and zone ownership remain Pending Operational Configuration.

### D-07 — Family Members

**Requirement ID:** D-07  
**Requirement Name:** Optional family profiles  
**Priority:** P2 / Post-MVP  
**Actors:** Family Account Owner, CRM Administrator  
**Business Goal:** Support household preferences and occasions with explicit privacy controls.  
**Current State:** No family-member model; customer occasions exist.  
**Target Requirement:** Post-MVP owner may initially maintain name, relationship, and birth date; preferences/allergies/notes are future extensions.  
**Preconditions:** Verified owner and explicit purpose/consent.  
**Trigger:** Owner adds/edits/deletes a family member.  
**Primary Flow:** Explain purpose → capture consent/minimum data → validate → save → allow correction/deletion.  
**Alternative Flows:** Owner skips feature; revokes marketing consent without losing required operational choices.  
**Failure Scenarios:** Missing consent, child-data policy unavailable, unauthorized staff access, sync failure.  
**Business Rules:** Optional and Post-MVP; never required for account/order; no financial Customer or independent login; allergies are assistance only and never replace explicit order confirmation; no campaign use without consent.  
**Validation Rules:** Relationship/date and sensitivity rules; child thresholds Pending E-10.  
**Permissions:** Owner edits; authorized CRM role only; support/accounting hidden unless explicitly justified.  
**Required Data:** Optional family attributes, consent purpose/version, owner reference.  
**Data Source:** Pending E Decision.  
**Data Written:** Family record and consent/audit metadata, never automatic financial identity.  
**Notifications:** None by default; marketing only with consent.  
**Audit Requirements:** Consent, access, changes and deletion request.  
**Privacy Requirements:** Sensitive/child data minimization, restricted visibility, retention/deletion policy.  
**Dependencies:** D-05, E-02, E-10.  
**Architecture Decisions Required:** Storage, child policy, staff access and retention.  
**Acceptance Criteria:** Feature remains optional; owner controls records; no Customer/login is created; unauthorized roles cannot view them.  
**Out of Scope:** Independent family accounts and automatic marketing.  
**Open Questions:** Storage remains Pending Architecture Decisions; child-data rules, lawful purpose and retention remain Pending Privacy Policy.

### D-08 — Occasions

**Requirement ID:** D-08  
**Requirement Name:** Customer and family occasions  
**Priority:** P2 / Post-MVP  
**Actors:** Portal Customer, Family Account Owner, authorized CRM staff  
**Business Goal:** Store optional reminders without unauthorized marketing.  
**Current State:** Laravel has customer occasions; portal/family/consent behavior is absent.  
**Target Requirement:** Manage `birthday`, `wedding_anniversary`, `graduation`, `family_event`, or `custom`, date, annual recurrence, subject, reminder and offer consent.  
**Preconditions:** Verified account; family subject exists if used.  
**Trigger:** Customer creates/changes/hides/deletes an occasion.  
**Primary Flow:** Choose type/subject/date → configure recurrence/reminder/marketing consent → save → display authorized projection.  
**Alternative Flows:** Reminder without marketing; hidden/inactive occasion; Feb 29 follows approved rule.  
**Failure Scenarios:** Invalid date, deleted family subject, timezone ambiguity, consent conflict, sync failure.  
**Business Rules:** Post-MVP; year is optional; call-center staff see only day/month and birth year is restricted/omitted by default; no promotional greeting after opt-out; operational reminder and marketing consent are distinct.  
**Validation Rules:** Valid date/type/timezone; custom label limits; explicit Feb 29 rule Pending Business Decision.  
**Permissions:** Owner edits; admin visibility is role/purpose restricted.  
**Required Data:** Type, date, recurrence, subject, timezone, reminder and consent state.  
**Data Source:** Laravel occasion where applicable; family/cloud placement Pending E.  
**Data Written:** Occasion, reminder preferences and consent evidence.  
**Notifications:** Reminder/offer only through consent-compatible channels.  
**Audit Requirements:** Create/change/hide/delete and consent changes.  
**Privacy Requirements:** Purpose limitation and family/child protections.  
**Dependencies:** D-07, D-13, E-07, E-10.  
**Architecture Decisions Required:** Ownership/sync, timezone and notification policy.  
**Acceptance Criteria:** Recurrence/subject/consent are explicit; opt-out blocks marketing; delete/hide works without corrupting history.  
**Out of Scope:** Campaign engine.  
**Open Questions:** Synchronization remains Pending Architecture Decisions; Feb 29/reminder defaults are Pending Operational Configuration; retention is Pending Privacy Policy.

### D-09 — Order History

**Requirement ID:** D-09  
**Requirement Name:** Safe order history and reorder  
**Priority:** P0 / MVP  
**Actors:** Portal Customer, System Integration  
**Business Goal:** Show owned Laravel orders and prepare a reviewed reorder using current facts.  
**Current State:** Website creates no Order; Laravel orders use customer links/snapshots and conflicting statuses.  
**Target Requirement:** Show one active order when present plus the last two completed orders, with opaque public number, date, branch, source, customer-facing state, total, items, fulfillment method and rating; retain full history for Admin only.  
**Preconditions:** Verified safely linked customer; synchronized authorized projection.  
**Trigger:** Customer opens history/details or chooses reorder.  
**Primary Flow:** Authorize ownership → show active + last two completed → select one of those completed orders → rebuild cart using current catalog/price/availability → show price changes → customer reviews.  
**Alternative Flows:** Discontinued/unavailable items are identified; price differences are explicit; stale history is labeled.  
**Failure Scenarios:** Unlinked account, unknown/guessable ID attempt, sync outage, partial item mapping, stale catalog.  
**Business Rules:** Never expose lifetime order/spend/average/consumption statistics or full history; never reuse old price/payment/reference; never submit automatically; Laravel is final order authority.  
**Validation Rules:** Opaque reference, ownership on every read, fixed approved history window, current item/branch validation.  
**Permissions:** Customer own orders only; authorized support access belongs to D2.  
**Required Data:** Public reference, timestamps, branch/source/status/total/items/method/rating and sync time.  
**Data Source:** Laravel final orders; customer mapping Pending E-02; catalog Pending E-09.  
**Data Written:** Reorder creates only a reviewed cart/draft according to E-04; no prior financial data copied.  
**Notifications:** None for viewing; order submission belongs to later requirements.  
**Audit Requirements:** Sensitive order access and reorder initiation/submission reference.  
**Privacy Requirements:** Hide internal notes, payment secrets, risk and staff data.  
**Dependencies:** D-04, D-10, E-02, E-04, E-05, E-07, E-09.  
**Architecture Decisions Required:** Public order ID, projection/storage and reorder draft location.  
**Acceptance Criteria:** Only the owned active order and last two completed appear; no aggregate/full history appears; reorder uses current price/availability, shows differences, and requires review.  
**Out of Scope:** Final checkout/payment design.  
**Open Questions:** Projection/public identifiers remain Pending Architecture Decisions; retention and displayed financial detail remain Pending Privacy Policy.

### D-10 — Order Tracking

**Requirement ID:** D-10  
**Requirement Name:** Customer-facing tracking timeline  
**Priority:** P0 / MVP  
**Actors:** Portal Customer, Customer Support Agent, System Integration  
**Business Goal:** Provide trustworthy progress without exposing internal workflow.  
**Current State:** Status vocabularies conflict; no unified execution timeline or durable sync.  
**Target Requirement:** Show current state/timeline/last update, branch, items, total, delivery address, ETA if available, delay and public cancellation reason. Proposed labels: received, awaiting payment confirmation, confirmed, preparing, ready, on the way, delivered, cancelled.  
**Preconditions:** Authorized final Laravel order and synchronized projection.  
**Trigger:** Order update, notification deep link, or detail refresh.  
**Primary Flow:** Receive deduplicated ordered event → map via E-05 → update projection/timeline → show timestamp/staleness → notify when eligible.  
**Alternative Flows:** Direct cancellation is accepted only before payment confirmation; afterward contact support. Modification request before preparation may include items, quantities, notes, or address, requires repricing and call-center acceptance; requests never change status directly.  
**Failure Scenarios:** Offline sync, duplicate/out-of-order event, unknown mapping, missing ETA, unauthorized order.  
**Business Rules:** Labels are not final internal statuses; Laravel confirms truth; direct cancellation ends at payment confirmation; ordinary modification ends when preparation starts; do not show internal notes or claim success before acknowledgement.  
**Validation Rules:** Ownership, monotonic/versioned event processing, safe reason codes, address masking.  
**Permissions:** Customer read/request only; support responds under D2; integration maps but does not invent states.  
**Required Data:** Public order ref, mapped state, ordered events/times, sync time, display-safe order/address/delay data.  
**Data Source:** Laravel; mapping Pending E-05; projection Pending E-01/E-07.  
**Data Written:** Tracking projection, deduplication/audit metadata, customer requests.  
**Notifications:** State, delay and cancellation events under D-13.  
**Audit Requirements:** Event reference/version, mapping, receipt/application time and request history.  
**Privacy Requirements:** No internal notes, staff identity, secrets or full sensitive address in notification.  
**Dependencies:** E-01, E-05, E-06, E-07, D-13.  
**Architecture Decisions Required:** Canonical mapping, event ordering, offline policy, update transport and ETA source.  
**Acceptance Criteria:** Duplicates/out-of-order events do not regress state; staleness is visible; only owned order displays; customer cannot mutate state.  
**Out of Scope:** Final internal status changes, WebSocket selection and delivery workflow implementation.  
**Open Questions:** Status/event/ETA design remains Pending Architecture Decisions; active-order timing, delay thresholds and public-reason configuration remain Pending Operational Configuration.

### D-11 — Loyalty

**Requirement ID:** D-11  
**Requirement Name:** Rewards balance, history and redemption request  
**Priority:** P1 / MVP read; Post-MVP redemption  
**Actors:** Portal Customer, CRM Administrator, System Integration  
**Business Goal:** Transparently present rewards without direct balance mutation.  
**Current State:** Laravel has only `customer.loyalty_points`; no ledger, expiry or redemption engine.  
**Target Requirement:** Show final/pending/available/expired/expiring amounts and future ledger/rewards; points finalize only after delivered + successfully closed; redemption is automatic within configured rules and escalated when large/suspicious.  
**Preconditions:** Verified linked customer and future authoritative ledger.  
**Trigger:** Open rewards, choose reward, or request redemption.  
**Primary Flow:** Load Laravel projection → choose eligible reward → submit idempotent request → Laravel validates/posts ledger → return confirmed result.  
**Alternative Flows:** Large/suspicious redemption reserves points pending approval; cancellation/refund posts reversal and used points create financial/administrative review; internal negative balance may be represented with a clear settlement description; referral earns once after the first paid, delivered, successfully closed order.  
**Failure Scenarios:** Insufficient/stale points, duplicate request, reward unavailable, sync outage, ledger absent.  
**Business Rules:** Website never calculates authority or directly edits balance; reward types are `invoice_discount`, `free_item`, `product_reward`, `upgrade`, `custom_reward`; every redemption is logged/notified to Admin; no points for incomplete/cancelled orders and no reset outside Ledger.  
**Validation Rules:** Opaque idempotency/reference, nonnegative request, current reward eligibility and ledger version.  
**Permissions:** Customer own rewards/request; privileged audited admin adjustment; accounting sees authorized consequences only.  
**Required Data:** Ledger balance buckets, entries, expiry, reward/rule version, redemption status/reference.  
**Data Source:** Laravel final; current balance alone is insufficient.  
**Data Written:** Future ledger transaction/request and audit; never direct balance edit.  
**Notifications:** Redemption, reversal, adjustment and expiry notices under consent/channel rules.  
**Audit Requirements:** Immutable earning/redemption/reversal/adjustment references and actors.  
**Privacy Requirements:** Own balance only; retention and notification exposure per E-10.  
**Dependencies:** E-06, E-07, E-10 and future loyalty-ledger/rules phases.  
**Architecture Decisions Required:** Ledger model, earning/expiry/refund rules, redemption financial treatment.  
**Acceptance Criteria:** Portal cannot directly mutate points; stale/duplicate redemption is safe; Laravel response determines outcome; reversal is traceable.  
**Out of Scope:** Implementing ledger/rules/rewards catalog.  
**Open Questions:** Ledger/idempotency mechanism remains Pending Architecture Decisions; thresholds/approvers remain Pending Operational Configuration; discount/payment accounting and refund settlement remain Pending Financial Design.

### D-12 — Conversations

**Requirement ID:** D-12  
**Requirement Name:** Customer support conversations  
**Priority:** P1 / MVP text  
**Actors:** Portal Customer, Customer Support Agent, System Integration  
**Business Goal:** Provide traceable account-based support communication.  
**Current State:** No portal conversations/messages or unified notification center.  
**Target Requirement:** Open `order_issue`, `complaint`, `general_inquiry`, or `suggestion` threads in one inbox; send MVP text; show unread, assignee, last reply and waiting party; close/reopen. Proposed states remain non-final schema.  
**Preconditions:** Verified account; referenced object owned when supplied.  
**Trigger:** Customer starts/replies/reopens or staff replies/closes.  
**Primary Flow:** Authorize context → create/idempotently send → notify unified Admin inbox → manual claim or configured auto-assignment → respond → notify customer → resolve/close; supervisor may reassign.  
**Alternative Flows:** Complaint conversion; attachments later; reopen by policy.  
**Failure Scenarios:** Duplicate message, unsafe attachment, unauthorized reference, offline delivery, closed-window reply.  
**Business Rules:** Normal conversation alert target is 10 minutes; active-order target is shorter/configurable; after-hours auto-response and work schedule adjust SLA. No general-chat attachments in MVP. Complaint images use file policy. Payment proof is a separate private, audited flow linked to order/payment attempt; JPG/PNG/WEBP/PDF are initially allowed after MIME/size/content checks and authorized review, and production cannot start before payment confirmation.  
**Validation Rules:** Text size/content safety, ownership, idempotency key, attachment policy before enabling.  
**Permissions:** Customer own threads; assigned/authorized support; CRM admin oversight; accounting restricted.  
**Required Data:** Thread/ref/type/state, participants, messages, unread, assignee, timestamps, safe external refs.  
**Data Source:** Pending E-01/E-10.  
**Data Written:** Conversation/message/audit and delivery state.  
**Notifications:** Staff on new message; customer on reply, without lock-screen-sensitive content.  
**Audit Requirements:** Creation, assignment, state changes, message IDs/delivery; content access policy.  
**Privacy Requirements:** Retention, export/deletion boundaries, attachment scanning and least access.  
**Dependencies:** D-13, D-14, E-01, E-06, E-07, E-10.  
**Architecture Decisions Required:** Storage, realtime/polling, channels, retention and attachment controls.  
**Acceptance Criteria:** Duplicate sends are suppressed; ownership is enforced; unread/waiting states are reliable; internal notes never display.  
**Out of Scope:** Voice/video, advanced chatbot, all-channel unification, initial attachments.  
**Open Questions:** Storage/realtime/payment-proof integration remain Pending Architecture Decisions; active-order SLA, auto-assignment delay, work schedule and file limits remain Pending Operational Configuration; file retention/access remain Pending Privacy Policy.

### D-13 — Notifications

**Requirement ID:** D-13  
**Requirement Name:** Operational and marketing notifications  
**Priority:** P1 / MVP in-app only; external channels Future  
**Actors:** Portal Customer, Customer Support Agent, System Integration  
**Business Goal:** Deliver timely, consent-aware, deduplicated notices.  
**Current State:** No unified portal notification center or verified channels.  
**Target Requirement:** Provide `in_app` notifications only in MVP, separating operational/security notices from consent-required marketing; defer push, WhatsApp, SMS and email.  
**Preconditions:** Event is authorized, classified and carries a safe reference; channel preference/consent known.  
**Trigger:** Eligible domain/security/marketing event.  
**Primary Flow:** Classify → check consent/preferences → deduplicate → render minimal content → send/store → record result → safe deep link.  
**Alternative Flows:** Fallback channel Pending E-10; critical security notice bypasses optional channel disablement but follows law/policy.  
**Failure Scenarios:** Duplicate event, invalid channel, revoked consent, provider outage, unsafe payload, stale deep link.  
**Business Rules:** Marketing is an unchecked optional registration choice, never blocks registration, can be withdrawn, and records version/time/source; operational/security messaging is separate and critical security cannot be disabled.  
**Validation Rules:** Event/reference/template/channel/consent version; `in_app`, `push`, `whatsapp`, `sms`, `email` remain candidates only.  
**Permissions:** Customer own preferences/notices; authorized campaign/admin roles; integration minimum delivery data.  
**Required Data:** Classification, recipient, safe reference, template/version, channel, consent/preference, delivery status.  
**Data Source:** Domain authority plus preference/consent Pending E-10.  
**Data Written:** Notification/delivery/deduplication/audit records.  
**Notifications:** This requirement governs them.  
**Audit Requirements:** Why/when/channel/template/consent and delivery outcome; redact content where necessary.  
**Privacy Requirements:** Minimized content, opt-out, retention and lawful consent.  
**Dependencies:** E-06, E-07, E-10 and emitting requirements.  
**Architecture Decisions Required:** Channels/providers, preference authority, fallback, retention and delivery guarantees.  
**Acceptance Criteria:** Marketing stops after opt-out; operational/security classes are distinct; duplicates are suppressed; deep links reauthorize.  
**Out of Scope:** Selecting WhatsApp/SMS/email/push providers.  
**Open Questions:** Notification store/delivery contract remains Pending Architecture Decisions; in-app delivery SLA/quiet behavior remain Pending Operational Configuration; consent retention and future-channel policy remain Pending Privacy Policy.

### D-14 — Complaints

**Requirement ID:** D-14  
**Requirement Name:** Complaint intake and follow-up  
**Priority:** P1 / MVP  
**Actors:** Portal Customer, Customer Support Agent, CRM Administrator  
**Business Goal:** Provide accountable resolution linked to the customer/order.  
**Current State:** Laravel has complaints/followups; portal intake and cross-system contract are absent.  
**Target Requirement:** Create general/order complaint with type, description, future attachments, display-safe status/priority/replies/expected response; close/reopen/rate resolution; convert conversation. Proposed states require reconciliation: `new`, `under_review`, `contacted`, `in_progress`, `resolved`, `closed`, `reopened`.  
**Preconditions:** Verified account; owned order if linked.  
**Trigger:** Customer submits or converts conversation; staff follows up.  
**Primary Flow:** Validate → create idempotently → notify/assign Admin → follow up/respond → notify customer → resolve/close → optional solution rating.  
**Alternative Flows:** General complaint, escalation, images under file policy, reopen within seven days; after seven days create a new complaint linked to the previous one.  
**Failure Scenarios:** Duplicate, unauthorized order, invalid attachment, unavailable sync, internal-note leakage.  
**Business Rules:** Customer never sees internal notes; priority may be internal; conversation-to-complaint conversion is audited; seven-day reopen policy is enforced; public status mapping needs approval.  
**Validation Rules:** Type/description, order ownership, idempotency, safe attachments when enabled.  
**Permissions:** Customer own complaint; assigned support and authorized CRM admins; financial/internal detail restricted.  
**Required Data:** Public reference, customer/order, type/text, status, priority, public replies, followups, SLA, rating.  
**Data Source:** Laravel final; portal projection Pending E-01/E-07.  
**Data Written:** Laravel complaint/followups through approved integration plus audit/projection.  
**Notifications:** Receipt, response, escalation/resolution/reopen under D-13.  
**Audit Requirements:** Full status, assignment, response, conversion, closure and reopen history.  
**Privacy Requirements:** Separate public/internal content; retention and attachment policy.  
**Dependencies:** D-12, D-13, E-01, E-05, E-06, E-07, E-10.  
**Architecture Decisions Required:** Status mapping, SLA, attachment storage, integration and retention.  
**Acceptance Criteria:** Complaint is traceable and deduplicated; order ownership enforced; staff receives it; internal notes never display; customer gets updates.  
**Out of Scope:** Attachment implementation and final operational workflow.  
**Open Questions:** Status/integration/file storage remain Pending Architecture Decisions; SLA, owner, escalation and public-priority configuration remain Pending Operational Configuration; image/complaint retention remain Pending Privacy Policy.

### D-15 — Ratings

**Requirement ID:** D-15  
**Requirement Name:** Unified order and restaurant feedback  
**Priority:** P1 / MVP  
**Actors:** Guest, Portal Customer, Customer Support Agent, System Integration  
**Business Goal:** Collect trustworthy feedback and trigger recovery without duplicating stores.  
**Current State:** Website `restaurant_ratings` is separate from Laravel `order_feedback`; website action lacks order/customer identity and established consent/rate limits.  
**Target Requirement:** Support completed-order rating and general restaurant rating across food, preparation, packaging, delivery, staff, comment and future images; low rating may request contact/follow-up/complaint.  
**Preconditions:** Completed owned order for order rating; approved guest policy for general rating.  
**Trigger:** Post-delivery prompt or public general-rating entry.  
**Primary Flow:** Select eligible context → rate/comment/consent → validate/deduplicate → store under approved authority → optionally request contact → notify Admin.  
**Alternative Flows:** Guest general rating requires verified name/phone and rate limiting; one edit is allowed within 24 hours with prior value retained; 3 stars or less creates follow-up, 1–2 is urgent, and customer is asked whether contact is desired.  
**Failure Scenarios:** Repeat order rating, ineligible order, abusive input, rate limit, sync outage, missing consent, unsafe image.  
**Business Rules:** One order rating with one permitted 24-hour edit and audit history; no public disclosure without consent; guest rating never links to an order without proven ownership; follow-up/complaint remains traceable.  
**Validation Rules:** Rating ranges/categories, completed-order ownership, content/rate limits, edit window and attachment policy.  
**Permissions:** Customer own feedback; guest limited general rating; authorized staff review/respond; public visibility opt-in only.  
**Required Data:** Rating type/order/customer/branch, category scores, comment, consent, contact request, timestamps/version.  
**Data Source:** Pending E Decision for unifying `restaurant_ratings` and `order_feedback`.  
**Data Written:** Unified/linked feedback, moderation/follow-up and audit metadata.  
**Notifications:** Admin on low/contact-request; customer on follow-up under D-13.  
**Audit Requirements:** Submission/edit/moderation/publication/contact/complaint conversion.  
**Privacy Requirements:** Consent for publication/contact, defined retention, PII separation and image controls.  
**Dependencies:** D-09, D-13, D-14, E-01, E-02, E-07, E-10.  
**Architecture Decisions Required:** Authoritative store, guest policy, edit window, thresholds, retention and image storage.  
**Acceptance Criteria:** Order ownership/completion enforced; duplicate policy enforced; low-rating follow-up is traceable; rating is private by default without consent.  
**Out of Scope:** Image implementation, public review feed and final moderation tooling.  
**Open Questions:** Authoritative store/linking remain Pending Architecture Decisions; moderation/follow-up ownership remains Pending Operational Configuration; publication consent and retention remain Pending Privacy Policy.

## 5. Requirements Matrix

| ID | Requirement | Priority | Release | Backend | Website | Admin | Cloud | Sync | Required decision |
| --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- |
| D-01 | Login | P0 | MVP | ✓ | ✓ | — | ✓ | ✓ | E-02,E-03,E-10 |
| D-02 | Registration | P0 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-02,E-03,E-10 |
| D-03 | Phone verification | P0 | MVP | ✓ | ✓ | — | ✓ | ✓ | E-02,E-03,E-10 |
| D-04 | Customer linking | P0 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-01,E-02,E-03,E-07 |
| D-05 | Profile | P0 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-02,E-03,E-07,E-10 |
| D-06 | Addresses | P0 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-07,E-09,E-10 |
| D-07 | Family | P2 | Post-MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-02,E-10 |
| D-08 | Occasions | P2 | Post-MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-07,E-10 |
| D-09 | Order history | P0 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-02,E-04,E-05,E-07,E-09 |
| D-10 | Tracking | P0 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-01,E-05,E-06,E-07 |
| D-11 | Loyalty | P1 | MVP read / Post-MVP redeem | ✓ | ✓ | ✓ | ✓ | ✓ | E-06,E-07,E-10 |
| D-12 | Conversations | P1 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-01,E-06,E-07,E-10 |
| D-13 | Notifications | P1 | MVP in-app / Future channels | ✓ | ✓ | ✓ | ✓ | ✓ | E-06,E-07,E-10 |
| D-14 | Complaints | P1 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-01,E-05,E-06,E-07,E-10 |
| D-15 | Ratings | P1 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-01,E-02,E-07,E-10 |

## 6. Data Matrix

| Data | Customer sees | Customer edits | Proposed source of truth | Sync | Sensitive | Consent |
| --- | ---: | ---: | --- | ---: | ---: | ---: |
| Name | Yes | Yes | Laravel / Pending E field authority | Yes | Yes | Purpose notice |
| Phone | Yes | After reverification | Laravel identity / Pending E-02 | Yes | Yes | Required for account |
| Email | Yes | Yes | Pending E Decision | Yes | Yes | Channel consent separately |
| Addresses | Yes | Yes | Pending E Decision | Yes | Yes | Operational purpose |
| Family | Yes | Yes | Pending E Decision | Yes | Highly | Explicit |
| Occasions | Yes | Yes | Pending E Decision | Yes | Yes | Explicit marketing separation |
| Orders | Yes | No | Laravel | Yes | Yes | Operational |
| Order status | Yes | No | Laravel mapped via E-05 | Yes | Yes | Operational |
| Points | Yes | Request only | Laravel future ledger | Yes | Financial-like | Operational/marketing separated |
| Messages | Yes | Sends own | Pending E Decision | Yes | Yes | Service; retention notice |
| Complaints | Yes | Creates/replies | Laravel | Yes | Yes | Service; retention notice |
| Ratings | Yes | Submit/edit by policy | Pending E Decision | Yes | Yes | Publication/contact explicit |
| Notification preferences | Yes | Yes | Pending E Decision | Yes | Yes | Explicit marketing consent |

## 7. Privacy and Permission Matrix

| Data type | Customer | Support Agent | CRM Administrator | Accountant | System Administrator |
| --- | ---: | ---: | ---: | ---: | ---: |
| Personal data | Edit | View | Edit | Restricted | Restricted |
| Phone | Edit | View | Edit | Restricted | Restricted |
| Addresses | Edit | View | Edit | Hidden | Restricted |
| Family members | Edit | Restricted | Restricted | Hidden | Restricted |
| Occasions | Edit | Restricted | Edit | Hidden | Restricted |
| Food allergies | Edit | Restricted | Restricted | Hidden | Restricted |
| Orders | View | View | View | View | Restricted |
| Points | View | View | Edit | Restricted | Restricted |
| Complaints | Edit | Edit | Edit | Hidden | Restricted |
| Internal notes | Hidden | Restricted | Edit | Hidden | Restricted |
| Financial data | Hidden | Restricted | Restricted | Edit | Restricted |
| Audit log | Hidden | Hidden | Restricted | Restricted | View |

`Edit` for points means privileged ledger adjustment only, never direct balance overwrite. All staff access remains subject to least privilege and D2 authorization design.

## 8. Non-Functional Requirements

### Security

- Rate-limit identity, messages, complaints, ratings and mutations; protect sessions, PII, CSRF where applicable, XSS, replay and enumeration.
- Use opaque public references and server-side ownership checks; never log OTP, payment secrets, raw session credentials or unnecessary PII.
- Audit sensitive access/mutations with correlation and idempotency references.

### Performance

- Account shell initial response target: **Proposed — Requires Approval:** p95 ≤ 2.5 seconds on representative mobile connectivity when dependencies are healthy.
- Paginated order/message lists: **Proposed — Requires Approval:** p95 ≤ 3 seconds; never fetch all history at once.
- Show loading/empty/error/stale states and avoid blocking the entire dashboard on one slow synchronization source.

### Availability

- When Laravel is unavailable, show the last synchronized read model with an explicit “last updated” warning.
- Never claim order, payment, redemption or complaint success before authoritative acknowledgement.
- Retries must be bounded, observable, idempotent and safe after reconnect; final policy depends on E-06/E-07.

### Usability and Accessibility

- Mobile-first RTL Arabic with clear language, consistent loading/empty/error states, keyboard and screen-reader support, semantic labels and focus management.
- Do not encode meaning by color alone; support readable contrast and recovery instructions.

### Privacy

- Record purpose/versioned consent, minimize collection, define retention, and support account deletion and correction requests.
- Future data export and portability are required; exact scope and legal/operational exceptions need E-10 approval.

## 9. End-to-End Scenarios

1. **New customer:** Register → verify phone → create minimal portal profile → add address → synchronize/show authorized account context in Admin; Laravel Customer creation follows E decision.
2. **Existing call-center customer:** Register → verify → find one safe Laravel Customer → link → show authorized past orders.
3. **Multiple matches:** Register → find multiple Customers → do not link/disclose → create review case → limited account or blocked linking per decision.
4. **Track order:** Locally approved order → status event → durable synchronization → deduplicated timeline update → customer notification.
5. **Synchronization outage:** Laravel unavailable → show last state/staleness → do not fabricate success → retry/reconcile after recovery.
6. **Redeem points:** Show ledger projection → choose reward → submit idempotent request → Laravel validates/posts ledger → notify confirmed/rejected result.
7. **Order complaint:** Select owned order → create complaint → notify/assign Admin → follow up/respond → notify customer → close/reopen by policy.
8. **Low rating:** Rate eligible order → low threshold → request contact consent → create traceable follow-up/complaint → notify Admin.

## 10. Open Questions

### Pending Architecture Decisions

- E-01 through E-10: integration location, identity/mapping, Portal Account authentication/session implementation, pre-approval storage, canonical status/event mapping, idempotency, durable offline synchronization, financial lifecycle timing, catalog/coverage authority, and privacy/channel architecture.
- OTP provider and delivery mechanism; device recognition and suspicious-activity signals; authoritative stores for family, messages, notifications and ratings; private payment-proof/file storage and scanning.

### Pending Operational Configuration

- OTP lifetime/attempt/resend/lock values; ambiguous-match reviewer and SLA; administrative recovery controls after phone loss.
- Coverage field rules, zone ownership/manual exceptions, Feb 29/reminder defaults, active-order modification/delay/ETA targets and public reason catalog.
- Conversation auto-assignment delay, active-order SLA, work schedule, complaint ownership/escalation, low-rating follow-up owner, moderation workflow, and file limits.
- Large/suspicious redemption thresholds and approval roles.

### Pending Privacy Policy

- Retention/access/deletion/export rules for identity attempts, sessions, profiles, addresses, family/child data, occasions, messages, files/payment proofs, complaints, ratings, notifications and audit evidence.
- Lawful purpose for optional sensitive fields, future external-channel consent, public rating publication, and account deletion/correction exceptions.

### Pending Financial Design

- Whether reward redemption is represented as discount or payment and how each reward type posts financially.
- E-08 invoice/payment timing, refund/cancellation settlement, negative loyalty balance treatment, and review when already-used points are reversed.

## 11. Architecture Decision Traceability

| Decision/topic | D1 questions that depend on it |
| --- | --- |
| E-01 Cloud Integration API Location | Linking, projections, tracking, messages, complaints, ratings |
| E-02 Synchronization Mode | Tracking, conversations, notifications, and freshness-sensitive projections |
| E-03 Conversation and Message Storage | Conversations, complaint communication, ordering, assignment, and message history |
| E-04 Notification Storage | In-app notices, preferences, delivery state, and notification audit |
| E-05 Website Order Pre-Approval Model | Reorder/pre-approval drafts, checkout handoff, and approval boundary |
| E-06 Payment Verification | Payment proof, confirmation, production gate, and customer-safe payment status |
| E-07 Offline and Retry Policy | Every cross-system projection/mutation and stale-data behavior |
| E-08 Conflict Resolution Policy | Concurrent/stale identity, catalog, order, and financial outcomes |
| E-09 Unified Customer Identity | Phone normalization, account linking, profile/order ownership |
| E-10 External Order Identifier and Reference Contract | External order references and cross-system correlation |
| EA-01 Portal Authentication and Sessions | Login, registration, sessions, recovery, and account states |
| EA-02 Canonical Status Dictionary | History, tracking, cancellation, complaint status, and fulfillment states |
| EA-03 Idempotency Contract | Tracking, redemption, messages, notifications, complaints, ratings, and approval |
| EA-04 Catalog and Pricing Authority | Address coverage, fees, reorder price, and availability |
| EA-05 Financial Posting Timing | Payment notices, points finality, refunds, and financial wording |
| EA-06 Privacy, Consent and Retention | Profiles, family, occasions, messages, notifications, complaints, and ratings |

All E-01 through E-10 and EA-01 through EA-06 remain **Pending**.
