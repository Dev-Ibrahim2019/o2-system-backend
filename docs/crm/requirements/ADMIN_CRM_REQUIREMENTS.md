# Admin CRM Requirements — D2

Status: **Ready for Business Review**  
Scope: D-16 through D-25. These are requirements, not implementation, schema, final permission names, or architecture decisions.

## 1. Executive Summary

D2 defines one governed administrative CRM experience for general management, CRM, call center, branches, finance, and system administration. It reuses the current CRM/Customer 360 and call-center capabilities, converges them on one Laravel operational customer and one reusable 360 shell, and varies tabs/actions by server-enforced permission. It preserves all approved D1 constraints: Portal Account is a linked identity rather than another Customer; Laravel confirms operational/financial truth; points change only through a future ledger; order state changes use business actions; sensitive data, payment proof, internal notes, family data, birth year, finance, risk, and audit are restricted.

## 2. Actors and Governing Principles

Actors are General Management, CRM Manager, Call Center Manager, Call Center Supervisor, Call Center Agent, Branch Manager, Accountant, System Administrator, and System Integration. Every view/action is authorized by Backend, audited in proportion to sensitivity, scoped by branch/assignment where required, and displays synchronization freshness. Existing Admin CRM and call-center CRM must not create competing profiles or sources.

## 3. Detailed Requirements

### D-16 — CRM Dashboard

**Requirement ID:** D-16  
**Requirement Name:** Daily CRM dashboard and work queues  
**Priority:** P0  
**Release:** MVP  
**Actors:** General Management, CRM Manager, Call Center Manager, Branch Manager  
**Business Goal:** Focus managers on customers and operations requiring action today.  
**Current State:** CRM and operational dashboards exist separately; no governed cross-system daily queue.  
**Target Requirement:** Role-aware cards for total/active/new/portal/limited/review/VIP/very-important/at-risk/inactive/active-order customers; unread/late conversations; open/late complaints; low ratings; today’s occasions; reward eligibility/redemptions; new/payment-review website orders; delayed operational orders.  
**Primary Users:** General and CRM management; call-center and branch managers with scoped views.  
**Preconditions:** Authorized role, defined metric/time/branch scope, and freshness metadata.  
**Primary Flow:** Select branch/date/customer/order/employee/follow-up filters → load summary → open Needs Attention, New Website Accounts, Identity Review, Unread Conversations, Open Complaints, Urgent Ratings, Today’s Occasions, Loyalty Redemptions, Delayed Orders, or Pending Website Orders → act in source workspace.  
**Alternative Flows:** Show cached/stale aggregates with last-sync warning; hide unauthorized card values while retaining permitted navigation.  
**Failure Scenarios:** Partial source outage, stale metric, double-counted identity, unauthorized drill-down, undefined SLA.  
**Business Rules:** Dashboard is operational, not a detailed financial report; one customer identity; counts must share documented definitions.  
**Display Requirements:** Cards include value, period, trend only when comparable, freshness, severity text/icon, and drill-down; no color-only meaning.  
**Filters and Search:** Branch, date, customer type/source, order source, employee, follow-up state.  
**Actions:** Drill down, assign/follow up, acknowledge permitted alert; no direct financial/status mutation.  
**Permissions:** VIEW_CRM_DASHBOARD plus domain permission and row scope for every card/list.  
**Sensitive Data Rules:** Mask/hide finance, family, risk, restriction reason, proof and audit by role.  
**Required Data:** Governed aggregates, alert/work-item references, owner, SLA and sync time.  
**Data Source:** Laravel operational data plus approved synchronized projections.  
**Data Written:** Saved filters, acknowledgement/assignment/follow-up audit only.  
**Audit Requirements:** Sensitive drill-down, export, assignment and alert disposition.  
**Notifications:** Manager escalation for overdue queues; in-app only in MVP.  
**SLA Requirements:** Proposed — Requires Business Approval: refresh ≤5 minutes for operational queues and daily reconciliation for aggregates.  
**Reporting Requirements:** Export only from authorized detailed reports; dashboard links to them.  
**Dependencies:** D-17,D-19,D-21..D-25; D1; E-01,E-02,E-05,E-07,E-10.  
**Architecture Decisions Required:** Projection location, identity deduplication, event freshness and canonical SLA/status mapping.  
**Acceptance Criteria:** Cards/queues reconcile to filtered detail; permissions hold at query and drill-down; stale data is explicit; action opens the correct record.  
**Out of Scope:** General ledger statements, predictive scoring, automated punishment.  
**Proposed Defaults:** Proposed — Requires Business Approval: default scope is today and authorized branches; Needs Attention orders by severity then age.

### D-17 — Customer Directory and Filters

**Requirement ID:** D-17  
**Requirement Name:** Central customer directory  
**Priority:** P0  
**Release:** MVP  
**Actors:** CRM staff, call center, branch managers, authorized finance/admin  
**Business Goal:** Find one operational Customer quickly and safely.  
**Current State:** Admin directory and call-center search overlap; phone locations and CRM experiences are duplicated.  
**Target Requirement:** Paginated central table with name, primary/allowed backup phone, type/value/relationship/treatment, preferred branch, source, portal badge/status, last/active order, authorized order/spend totals, points, open complaint/unread conversation/upcoming occasion, last contact, owner, quick actions.  
**Primary Users:** CRM Manager and call-center users.  
**Preconditions:** Backend permission, branch/customer scope and normalized searchable identifiers.  
**Primary Flow:** Search/filter → server returns page → inspect badges/alerts → open shared Customer 360 or permitted quick action.  
**Alternative Flows:** Masked result for restricted fields; no-result offers only authorized customer-resolution flow.  
**Failure Scenarios:** Ambiguous phone, stale portal projection, excessive export, CSV injection, bulk-action misuse.  
**Business Rules:** Search never creates a duplicate Customer; all pages use one Laravel identity; export and dangerous bulk actions require permission/confirmation.  
**Display Requirements:** Configurable columns, pagination/count, sort, saved-view badge, last update, loading/empty/error states.  
**Filters and Search:** Name, normalized phone, Customer/public UUID, email, order number, company; all branch/type/value/relationship/treatment/source/portal/review/order/complaint/message/occasion/redemption/inactivity/spend/count/contact/owner filters.  
**Actions:** Open 360, start call-center order/conversation/follow-up where permitted, save view, export.  
**Permissions:** VIEW_CUSTOMERS; sensitive aggregates/export require additional permissions.  
**Sensitive Data Rules:** Phone masking by role; finance/spend, restriction/risk, family and internal notes hidden unless authorized.  
**Required Data:** Directory projection, normalized identifiers, portal/link status, authorized aggregates and activity flags.  
**Data Source:** Laravel Customer; Portal linkage Pending E-02/E-03.  
**Data Written:** Saved views, export audit, assignment/follow-up; no duplicate profile.  
**Audit Requirements:** Export criteria/count/actor, sensitive access, bulk actions.  
**Notifications:** None for ordinary search; follow-up actions may notify owners.  
**SLA Requirements:** Proposed — Requires Business Approval: debounced search responds p95 ≤2 seconds under representative load.  
**Reporting Requirements:** Authorized export protects CSV formulas and applies current filters/masking.  
**Dependencies:** D-18,D-19,D-20; E-02,E-07,E-10.  
**Architecture Decisions Required:** Unified identifier/search index, portal projection and aggregate freshness.  
**Acceptance Criteria:** Server pagination/filtering works; common phone formats resolve consistently; saved views restore; unauthorized data never appears/export.  
**Out of Scope:** Final indexing technology and mass destructive updates.  
**Proposed Defaults:** Proposed — Requires Business Approval: 25 rows/page; saved views private unless explicitly shared by a manager.

### D-18 — Classifications, Tags and Segments

**Requirement ID:** D-18  
**Requirement Name:** Governed customer classification and segmentation  
**Priority:** P1  
**Release:** MVP classifications/tags; Post-MVP dynamic campaigns  
**Actors:** CRM Manager, authorized supervisors, campaign approvers  
**Business Goal:** Organize customers without losing history or bypassing consent.  
**Current State:** Customer classification fields exist, but no unified governed taxonomy/segment lifecycle.  
**Target Requirement:** Manage value (`VIP`, `very_important`, `important`, `normal`, `new`), relationship (`active`, `inactive`, `at_risk`, `needs_follow_up`, `has_open_issue`), treatment (`normal`, `under_observation`, `restricted`, `undesirable`, `blocked`), type (`individual`, `family`, `company`, `organization`, `event_customer`), flexible tags, and manual/dynamic/saved/temporary segments.  
**Primary Users:** CRM Manager and approved supervisors.  
**Preconditions:** Authorized taxonomy/action; consent filter for marketing use.  
**Primary Flow:** Select customer/filter → preview change/membership → provide reason/approval if needed → apply → preserve history → update authorized views.  
**Alternative Flows:** Automated rule proposes/applies according to governance; temporary segment expires; sensitive classification awaits approval.  
**Failure Scenarios:** Circular/overlapping rules, stale membership, unconsented campaign inclusion, unexplained blocked status.  
**Business Rules:** Manual change needs reason; sensitive/undesirable/blocked needs permission and potentially approval; retain actor/time/reason/history; classification does not itself expose reason to customer.  
**Display Requirements:** Current badges plus effective/history indicators; segment member preview/count/date range/consent exclusions.  
**Filters and Search:** All axes, tag, rule source, effective dates, owner, consent and segment type.  
**Actions:** Add/remove tag; propose/approve/change classification; create/preview/freeze/expire segment.  
**Permissions:** MANAGE_CUSTOMER_CLASSIFICATION; sensitive treatment and shared segments restricted/approval-required.  
**Sensitive Data Rules:** Risk/restriction/undesirable reasons visible only to authorized roles and never used unlawfully.  
**Required Data:** Taxonomy/version, assignment source/reason/effective history, segment definition/membership snapshot/consent state.  
**Data Source:** Laravel; dynamic inputs from governed projections.  
**Data Written:** Versioned classification/tag/segment and approval audit.  
**Audit Requirements:** Full before/after/reason/rule/approver and campaign membership snapshot.  
**Notifications:** Notify owner/manager on approved sensitive treatment changes.  
**SLA Requirements:** Proposed — Requires Business Approval: sensitive classification approval within one business day.  
**Reporting Requirements:** Counts and movement by classification/segment without exposing restricted reasons.  
**Dependencies:** D-17,D-24; E-02,E-07,E-10.  
**Architecture Decisions Required:** Rule evaluation/projection and consent synchronization.  
**Acceptance Criteria:** History is immutable; manual reasons required; consent exclusions work; dynamic membership is explainable.  
**Out of Scope:** ML scoring and automatic punitive treatment.  
**Proposed Defaults:** Proposed — Requires Business Approval: blocked/undesirable require CRM Manager approval; tags are manager-governed.

### D-19 — Portal Accounts and Identity Review

**Requirement ID:** D-19  
**Requirement Name:** Portal Account administration and identity review  
**Priority:** P0  
**Release:** MVP  
**Actors:** CRM Manager, authorized identity reviewer, System Administrator  
**Business Goal:** Resolve portal-to-Customer linkage without takeover, leakage or merge side effects.  
**Current State:** No Portal Account/UUID/mapping; phones are fragmented.  
**Target Requirement:** Filter/badge `no_portal_account`, `pending_verification`, `active`, `limited`, `identity_review`, `business_restricted`, `security_suspended`, `closed` (non-final schema); show creation/last login, verified/backup phone, verification/link/limited reason, marketing consent, future device count, recovery state, source/referral.  
**Primary Users:** CRM identity reviewers.  
**Preconditions:** Verified review case, reviewer permission, candidate evidence and D1 constraints.  
**Primary Flow:** Open case → view authorized candidates/reasons → select correct Customer → enter reason/confirmation → audited link → activate allowed history/points.  
**Alternative Flows:** Reject, remain limited, refer to separate merge process, security suspend, business restrict, exceptional recovery, or restricted unlink.  
**Failure Scenarios:** Concurrent link, wrong candidate, stale verification, attempted takeover, reviewer lacks scope.  
**Business Rules:** One Customer remains operational identity; OTP ownership does not resolve duplicates; merge never occurs inside linking; new single verified match follows D1 auto-link.  
**Display Requirements:** Case severity/age/evidence/candidates masked by permission; before/after link and consent status.  
**Filters and Search:** Portal state, review reason/age, registration source, reviewer, branch, verified phone, referral.  
**Actions:** Start/approve/reject review, keep limited, refer merge, suspend/restrict, exceptional recovery, restricted unlink.  
**Permissions:** MANAGE_PORTAL_ACCOUNTS and REVIEW_CUSTOMER_IDENTITY; recovery/unlink/suspension require stronger confirmation.  
**Sensitive Data Rules:** Candidate data only to reviewer; recovery/device/security evidence highly restricted.  
**Required Data:** Opaque account/link/case refs, evidence, candidate score/reasons, consent, state/history.  
**Data Source:** Portal identity Pending E-03; Laravel Customer; mapping Pending E-02.  
**Data Written:** Decision/link/restriction/recovery audit; no merge.  
**Audit Requirements:** Case access, evidence, before/after link, actor/reason/approver/correlation.  
**Notifications:** In-app reviewer queue and safe customer outcome where appropriate.  
**SLA Requirements:** Proposed — Requires Business Approval: review within 4 business hours; urgent recovery within 1 hour.  
**Reporting Requirements:** Open/aged/outcome/reversal counts without leaking candidate PII.  
**Dependencies:** D1 D-01..D-04; E-01,E-02,E-03,E-07,E-10.  
**Architecture Decisions Required:** Portal ownership, mapping/UUID, case store and recovery/session revocation.  
**Acceptance Criteria:** Only authorized reviewer sees candidates; every decision has reason/audit; limited data stays hidden until valid link; merge is separate.  
**Out of Scope:** Customer merge implementation and device management MVP.  
**Proposed Defaults:** Proposed — Requires Business Approval: two-person approval for unlink/recovery when prior order/points history exists.

### D-20 — Customer 360

**Requirement ID:** D-20  
**Requirement Name:** Reusable permission-aware Customer 360  
**Priority:** P0  
**Release:** MVP  
**Actors:** CRM, call center, branch, finance and system roles  
**Business Goal:** Give each role one coherent Customer context with safe actions.  
**Current State:** Existing Admin Customer 360 and broader call-center CRM overlap; reusable CRM components/services exist.  
**Target Requirement:** One reusable shell with header identity/type/value/relationship/treatment/portal/branch/points/order/complaint/message/occasion/alert/owner and lazy tabs: Overview, Orders, Addresses, Portal Account, Loyalty, Conversations, Complaints, Ratings, Occasions, Notes, Financial, Activity Log.  
**Primary Users:** All authorized customer-facing/management roles.  
**Preconditions:** Customer exists; role/branch/tab permissions resolved server-side.  
**Primary Flow:** Open from directory/context → load header/alerts → lazy-load allowed tab → perform permitted quick action → audit/update view.  
**Alternative Flows:** Call center sees optimized shortcuts; finance sees finance tab; restricted tabs are absent and Backend rejects direct access.  
**Failure Scenarios:** Conflicting duplicated profile, tab partial outage, stale aggregate, unauthorized deep link, unsafe quick action.  
**Business Rules:** Reuse current Customer 360 shell/components; one data source/profile; actions differ by role; points/status use business services, never direct fields.  
**Display Requirements:** Header listed above; overview includes authorized order history/active order/products/average/last rating/complaint/conversation/redemption/occasion/recommendations/alerts.  
**Filters and Search:** Tab-local server filtering; activity date/type/actor; order/complaint/conversation status.  
**Actions:** Call-center order, conversation, follow-up, complaint, in-app notice, classification/tag, active order, identity review, privileged ledger adjustment, restrict/suspend.  
**Permissions:** Composite domain permissions; every tab/action separately enforced.  
**Sensitive Data Rules:** Finance, secret notes, family, birth year, risk/restriction reason, proof and full audit restricted; sensitive action needs reason/confirmation/reverification.  
**Required Data:** Customer 360 projection and per-domain authoritative detail/freshness.  
**Data Source:** Laravel plus approved portal projections.  
**Data Written:** Domain action only through approved service; UI preferences/audit.  
**Audit Requirements:** Sensitive tab access/export and every sensitive action.  
**Notifications:** Action-specific in-app notices only where required.  
**SLA Requirements:** Proposed — Requires Business Approval: header p95 ≤2 seconds; each lazy tab p95 ≤3 seconds.  
**Reporting Requirements:** Links to domain reports; no hidden data inferred through totals.  
**Dependencies:** D-17..D-25; E-01,E-02,E-05,E-07,E-10.  
**Architecture Decisions Required:** Shared projection/API boundary, identifier and freshness.  
**Acceptance Criteria:** Same Customer opens from Admin/call center; tabs/actions match permissions; partial failure is isolated; no duplicate profile is created.  
**Out of Scope:** Rebuilding reusable UI without need and final visual design.  
**Proposed Defaults:** Proposed — Requires Business Approval: Overview/Orders/Addresses available to standard CRM roles; other tabs explicit grants.

### D-21 — Loyalty Administration

**Requirement ID:** D-21  
**Requirement Name:** Loyalty rules, ledger, redemption and referrals administration  
**Priority:** P1  
**Release:** Post-MVP after ledger foundation  
**Actors:** CRM Manager, loyalty operator/approver, Accountant, General Management  
**Business Goal:** Govern rewards with immutable financial-like evidence.  
**Current State:** Only customer balance exists; no ledger, rule, expiry or redemption engine.  
**Target Requirement:** Dashboard earned/redeemed/pending/expired/today approval/referral metrics; versioned rules by invoice amount/threshold/item/category/branch/source/customer class/multiplier/referral/occasion/cap/dates/priority/stacking; redemption approval/reservation/reversal; audited adjustments; referral lifecycle.  
**Primary Users:** CRM loyalty team and authorized finance oversight.  
**Preconditions:** Future ledger/rules contract and permission; Laravel authoritative order outcome.  
**Primary Flow:** Configure/approve rule → qualifying event posts idempotent ledger → customer requests redemption → automatic or approval path → reserve/post/reject → notify/audit.  
**Alternative Flows:** Refund/cancellation reversal; large adjustment needs second approval; referral rejected for self/duplicate/ineligible order.  
**Failure Scenarios:** Duplicate posting, stale balance, overlapping rules, expired reservation, refund after spend, direct balance edit.  
**Business Rules:** No direct balance mutation; reason required; points final after delivered+closed; used-point reversal triggers review; link order/invoice; show before/after.  
**Display Requirements:** Ledger, rule versions/effective dates, reservation/approval queues, referral evidence and readable settlement description.  
**Filters and Search:** Customer, rule/reward/source/branch/status/date/approver/order/invoice/referral.  
**Actions:** Draft/approve/retire rule, approve/reject redemption, audited add/deduct/zero adjustment, reverse, investigate referral.  
**Permissions:** MANAGE_LOYALTY; ADJUST_LOYALTY; APPROVE_LOYALTY_REDEMPTION; finance read/approval separation.  
**Sensitive Data Rules:** Fraud flags/financial posting/reasons restricted; customer sees understandable result only.  
**Required Data:** Immutable ledger, rule/reward versions, reservation, approval, order/invoice/referral refs.  
**Data Source:** Laravel final orders/payments and future loyalty ledger.  
**Data Written:** Ledger/rule/reservation/approval/reversal; never balance overwrite.  
**Audit Requirements:** Full actor/reason/approver/before-after/idempotency/correlation.  
**Notifications:** Admin record for every redemption; customer in-app result.  
**SLA Requirements:** Proposed — Requires Business Approval: approval queue resolved within 2 business hours.  
**Reporting Requirements:** Earned/redeemed/liability-like totals, referral outcomes, future cost vs sales with finance-approved definition.  
**Dependencies:** D1 D-11; E-05,E-06,E-07,E-08,E-10.  
**Architecture Decisions Required:** Ledger/rule engine, posting timing/idempotency and accounting treatment.  
**Acceptance Criteria:** Duplicate event posts once; no direct edit; approvals/reversals trace; D1 earning/referral rules hold.  
**Out of Scope:** Implementing ledger/schema and punitive automated fraud decisions.  
**Proposed Defaults:** Proposed — Requires Business Approval: manager approval above configurable points threshold; second approval for privileged adjustment above a higher threshold.

### D-22 — Conversation Center

**Requirement ID:** D-22  
**Requirement Name:** Unified conversation inbox  
**Priority:** P0  
**Release:** MVP  
**Actors:** Agent, Supervisor, Call Center Manager, CRM Manager  
**Business Goal:** Respond to customer messages within transparent ownership and SLA.  
**Current State:** No portal conversations; call-center tooling exists without unified cross-channel inbox.  
**Target Requirement:** Inbox columns for customer/type/order/last message/time/unread/priority/assignee/waiting party/age/wait/SLA/account status/classification; types and proposed states from D1.  
**Primary Users:** Call-center agents and supervisors.  
**Preconditions:** Authorized inbox scope and synchronized authenticated customer thread.  
**Primary Flow:** New message → unified queue → manual claim or timed auto-assignment → respond → customer in-app notification → wait/resolve/close.  
**Alternative Flows:** Supervisor reassigns/transfers; link order; convert to complaint; add hidden internal note; reopen by policy.  
**Failure Scenarios:** Duplicate/out-of-order message, overload, sync outage, wrong assignment, internal-note leak, forbidden proof access.  
**Business Rules:** Full assignment history; configurable capacity; normal alert starts at 10 minutes, active-order shorter, after-hours schedule adjusts SLA; general attachments excluded MVP.  
**Display Requirements:** Thread, ownership/SLA banners, customer/order context, public vs internal composer clearly separated.  
**Filters and Search:** Type/state/priority/assignee/department/branch/unread/SLA/order/account/classification/date.  
**Actions:** Reply, claim/assign/transfer, complaint conversion, order link, internal note, priority, resolve/close/reopen.  
**Permissions:** VIEW_CONVERSATIONS and MANAGE_CONVERSATIONS; supervisor reassign; proof separately VIEW_PAYMENT_PROOF.  
**Sensitive Data Rules:** Accountant hidden by default; internal notes never customer-visible; proof only authorized/private/audited.  
**Required Data:** Thread/message/order/customer/assignment/state/SLA/delivery metadata.  
**Data Source:** Pending E-01/E-10 with Laravel customer/order context.  
**Data Written:** Messages, assignment/state/internal note/audit and notification intent.  
**Audit Requirements:** Assignment history, state/priority, conversion, sensitive access and message delivery references.  
**Notifications:** In-app customer reply; agent/supervisor queue/escalation.  
**SLA Requirements:** Normal 10-minute initial alert; Proposed — Requires Business Approval: active-order 3 minutes, yellow at target, red at 2× target; configurable and schedule-aware.  
**Reporting Requirements:** Volume, first response, resolution, breached SLA, assignment/transfer and backlog by team; no automatic punishment.  
**Dependencies:** D1 D-12,D-13; D-20,D-23; E-01,E-06,E-07,E-10.  
**Architecture Decisions Required:** Store, ordering/deduplication, delivery method and offline policy.  
**Acceptance Criteria:** One inbox; duplicate suppressed; manual/auto assignment audited; SLA schedule works; internal/proof data never leaks.  
**Out of Scope:** Voice/video/chatbot and general attachments.  
**Proposed Defaults:** Proposed — Requires Business Approval: manual claim first, auto-assign after 2 minutes if unclaimed and capacity exists.

### D-23 — Complaints, Ratings, Occasions and Follow-up

**Requirement ID:** D-23  
**Requirement Name:** Unified customer-experience work management  
**Priority:** P0  
**Release:** MVP complaints/ratings/follow-up; Post-MVP family occasions  
**Actors:** Agents, supervisors, CRM Manager, authorized branch staff  
**Business Goal:** Track every service-recovery commitment to resolution.  
**Current State:** Laravel complaints/occasions/followups and split rating stores exist; no unified queue/follow-up object.  
**Target Requirement:** Complaint dashboard new/review/late/high/reopened/active-order/VIP/SLA/closed-today; full complaint/public/internal/image/SLA/resolution data; rating queues for 1–2 urgent, 3 follow-up, contact request/unfollowed/complaint/edit/verified guest; occasion day/week/consent/subject with restricted year; unified follow-up linked to customer/complaint/rating/conversation/order/occasion/identity review.  
**Primary Users:** CRM/customer-experience team and call center.  
**Preconditions:** Authorized customer context; complaint/rating/occasion D1 rules and consent.  
**Primary Flow:** Intake/alert → assign follow-up owner/date/priority → investigate/public response or action → record outcome/next step → resolve/close → customer in-app update.  
**Alternative Flows:** Complaint reopen within 7 days; after that create linked new complaint; low rating becomes conversation/complaint; occasion marketing excludes no-consent.  
**Failure Scenarios:** Internal-note leak, lost image, duplicate follow-up, missed SLA, wrong rating threshold, birth-year exposure.  
**Business Rules:** D1 thresholds/reopen/edit rules; conversion audited; images protected; follow-up requires owner/due/state/result/next step/closure reason.  
**Display Requirements:** Queues, SLA text/icon, customer/order/VIP context, public/internal separation, image viewer access control.  
**Filters and Search:** Domain/state/priority/SLA/owner/branch/order/customer/rating/contact/consent/occasion date/reopened.  
**Actions:** Assign/escalate/respond/follow up/resolve/close/reopen/convert; no hidden-note publication.  
**Permissions:** VIEW/MANAGE_COMPLAINTS plus domain-sensitive permissions; image access restricted.  
**Sensitive Data Rules:** Internal notes, family, birth year, images and complaint details minimum-access; call center sees occasion day/month only.  
**Required Data:** Complaint/rating/occasion/follow-up records, public/internal content, consent, SLA and links.  
**Data Source:** Laravel complaints/occasions; rating authority Pending E; portal projection Pending E.  
**Data Written:** Domain actions/followups/audit and in-app notification intent.  
**Audit Requirements:** Assignment, access to sensitive image, public/internal reply, conversion, reopen/link, rating edit history.  
**Notifications:** Customer/admin in-app per event and consent classification.  
**SLA Requirements:** Proposed — Requires Business Approval: urgent rating acknowledged 15 minutes; standard complaint 30 minutes; resolution target configured by type/branch.  
**Reporting Requirements:** Intake, aging, first response/resolution, reopen, rating recovery, root cause and follow-up completion.  
**Dependencies:** D1 D-08,D-14,D-15; D-20,D-22,D-24; E-01,E-05,E-06,E-07,E-10.  
**Architecture Decisions Required:** Unified rating authority, event/follow-up store, files and retention.  
**Acceptance Criteria:** D1 thresholds/reopen hold; every follow-up owned/due; sensitive fields hidden; conversions/history remain traceable.  
**Out of Scope:** Final file implementation and automated disciplinary action.  
**Proposed Defaults:** Proposed — Requires Business Approval: complaints sort overdue/priority then age; low rating always creates follow-up, complaint only by rule/agent decision.

### D-24 — Notifications and Campaigns

**Requirement ID:** D-24  
**Requirement Name:** In-app notifications and consent-aware campaigns  
**Priority:** P1  
**Release:** MVP in-app; external channels Future  
**Actors:** CRM Manager, campaign creator/approver, authorized operational staff  
**Business Goal:** Communicate operationally and market only with valid consent.  
**Current State:** No unified portal notification center/campaign governance.  
**Target Requirement:** Send in-app to one/many/segment/branch/portal/occasion/reward-eligible/inactive/order audiences; separate operational order/payment/delay/complaint/conversation/points/security from marketing offer/occasion/expiry/reactivation/branch/class campaigns.  
**Primary Users:** CRM campaign team; operational staff for individual notices.  
**Preconditions:** Authorized sender, template/version, safe reference, audience preview, consent classification and approval where required.  
**Primary Flow:** Choose type/audience → preview count/sample/exclusions → compose versioned template → approve if required → schedule/send idempotently → monitor results/audit.  
**Alternative Flows:** Individual operational send without campaign approval; withdrawal excludes future marketing; failed delivery retries safely.  
**Failure Scenarios:** Unconsented recipient, duplicate send, stale segment, wrong branch, sensitive content, approval bypass.  
**Business Rules:** MVP in_app only; unchecked/withdrawable marketing consent; operational/security separate; creator/approver separation for governed bulk campaigns.  
**Display Requirements:** Campaign name/creator/approver/schedule/state/results/template version and audience/exclusion preview.  
**Filters and Search:** Type/status/creator/approver/date/branch/segment/template/consent/result.  
**Actions:** Draft, preview, test to authorized internal account, submit/approve/reject, schedule/cancel before send, inspect result.  
**Permissions:** SEND_CUSTOMER_NOTIFICATION; CREATE_MARKETING_CAMPAIGN; APPROVE_MARKETING_CAMPAIGN.  
**Sensitive Data Rules:** Template/deep link uses minimal safe data; audience sample masked; no hidden criteria disclosure.  
**Required Data:** Campaign/template/audience snapshot, consent evidence, dedupe key, delivery/result.  
**Data Source:** Customer/segment/consent projections; domain events.  
**Data Written:** Notification/campaign/approval/delivery/audit records.  
**Audit Requirements:** Creator/approver/audience criteria/count/template/version/schedule/result/cancel.  
**Notifications:** In-app only by definition for MVP.  
**SLA Requirements:** Proposed — Requires Business Approval: operational events queued within 1 minute; campaigns execute within approved window.  
**Reporting Requirements:** Intended/excluded/sent/delivered/read/failed counts without implying engagement causality.  
**Dependencies:** D1 D-13; D-18,D-22,D-23,D-25; E-06,E-07,E-10.  
**Architecture Decisions Required:** Notification store/delivery/dedupe, consent authority and retry policy.  
**Acceptance Criteria:** No opted-out customer in marketing; operational remains separate; duplicate event sends once; bulk approval/audit enforced.  
**Out of Scope:** Push/WhatsApp/SMS/email and provider selection.  
**Proposed Defaults:** Proposed — Requires Business Approval: manager approval for marketing to >100 recipients; individual operational notice needs no campaign approval.

### D-25 — Operations and Website Orders

**Requirement ID:** D-25  
**Requirement Name:** Operations monitoring and safe website-order review  
**Priority:** P0  
**Release:** MVP after E decisions/integration foundation  
**Actors:** Call-center/branch/operations managers, authorized approver, Accountant  
**Business Goal:** Monitor all active orders and import website orders exactly once into Laravel.  
**Current State:** Partial dashboards; no website-order ingestion, unified timeline, durable sync or canonical statuses.  
**Target Requirement:** Active table across call_center/website/pos/hospitality with order/customer/classification/branch/source/method/state/stage/stage+total time/owner/department/SLA/payment/driver/problem; details include items/prices/discount/payment/address/allowed notes/timeline/production/assembly/delivery/times/reasons/decisions. Website queue proposed states `new`, `payment_review`, `ready_for_approval`, `approved`, `rejected`, `imported`, `processing`, `failed` (non-final schema).  
**Primary Users:** Operations/call-center/branch managers and web-order approvers.  
**Preconditions:** E-05..E-09 contracts, authenticated authorized action, external reference/idempotency and current catalog/payment evidence.  
**Primary Flow:** Receive external order → review customer/link/branch/address/items/sent-vs-current price/fee/payment/reference/private proof → atomically validate unprocessed/customer/branch/items/prices/payment → create local order/items/invoice/payment/transaction → confirm → tickets/departments/timeline → update website → notify customer.  
**Alternative Flows:** Reject with mandatory internal/public reasons and preserved proof; route financial uncertainty to review; failed transaction shows Failed/Needs Review with correlation and safe retry.  
**Failure Scenarios:** Duplicate import/production, orphan invoice/payment, partial silent success, price/customer mismatch, lost proof, out-of-order sync.  
**Business Rules:** No direct status-to-preparing; business actions only. No production before authorized payment confirmation. Employee metrics inform review, never automatic punishment.  
**Display Requirements:** SLA text/icon/color, sync/freshness/correlation, sent/current price difference, allowed notes, proof only to authorized role.  
**Filters and Search:** Source/branch/stage/status/SLA/payment/owner/department/driver/date/problem/link/review outcome.  
**Actions:** Open timeline, record delay reason/action, claim/reassign, approve/reject/retry safely, request information, financial review.  
**Permissions:** VIEW_OPERATIONS, VIEW_ORDER_TIMELINE, APPROVE_WEB_ORDERS, REJECT_WEB_ORDERS, VIEW_PAYMENT_PROOF; financial confirmation separated.  
**Sensitive Data Rules:** Proof private; payment/internal notes/risk restricted; customer-safe rejection reason separate.  
**Required Data:** External/correlation/idempotency refs, full order review snapshot, proof metadata, canonical events/SLA, processing attempts/results.  
**Data Source:** Website pre-order Pending E-04; Laravel final customer/order/invoice/payment/production; catalog Pending E-09.  
**Data Written:** Transactional Laravel records and durable integration/timeline/audit outcomes.  
**Audit Requirements:** Every review/check/decision/attempt/financial confirmation/status business action with before/after and correlation.  
**Notifications:** Customer in-app receipt/rejection/approval/tracking; internal delay/payment/review alerts.  
**SLA Requirements:** Proposed — Requires Business Approval: overall <20 normal, 20–30 yellow, >30 red; configurable per stage/branch, pausable by approved stage, never color-only. Stages include payment_review, call_center_approval, production, assembly, driver_waiting, delivery.  
**Reporting Requirements:** Approval time/count/error/modification/cancellation, production/assembly/driver/delivery time, late orders/reasons; contextual management review only.  
**Dependencies:** D1 D-09,D-10,D-12,D-13; E-01,E-02,E-04,E-05,E-06,E-07,E-08,E-09,E-10.  
**Architecture Decisions Required:** All integration/identity/order/status/idempotency/offline/finance/catalog contracts.  
**Acceptance Criteria:** Same external ref processes once; failure leaves no orphan/duplicate and is visible/recoverable; proof/payment authorization holds; timeline/SLA explains stage/owner.  
**Out of Scope:** Implementing ingestion, schema, state unification or automated employee punishment.  
**Proposed Defaults:** Proposed — Requires Business Approval: oldest red website order first; approval and payment confirmation require distinct permissions.

## 4. Preliminary Permission Catalog

Names are requirement labels, not final permission identifiers: `VIEW_CRM`, `VIEW_CRM_DASHBOARD`, `VIEW_CUSTOMERS`, `MANAGE_CUSTOMERS`, `VIEW_CUSTOMER_SENSITIVE_DATA`, `VIEW_CUSTOMER_FINANCE`, `MANAGE_CUSTOMER_CLASSIFICATION`, `MANAGE_PORTAL_ACCOUNTS`, `REVIEW_CUSTOMER_IDENTITY`, `MANAGE_LOYALTY`, `ADJUST_LOYALTY`, `APPROVE_LOYALTY_REDEMPTION`, `VIEW_CONVERSATIONS`, `MANAGE_CONVERSATIONS`, `VIEW_COMPLAINTS`, `MANAGE_COMPLAINTS`, `SEND_CUSTOMER_NOTIFICATION`, `CREATE_MARKETING_CAMPAIGN`, `APPROVE_MARKETING_CAMPAIGN`, `VIEW_OPERATIONS`, `VIEW_ORDER_TIMELINE`, `APPROVE_WEB_ORDERS`, `REJECT_WEB_ORDERS`, `VIEW_PAYMENT_PROOF`.

## 5. Permission Matrix

| Function | General Admin | CRM Manager | Call Center Manager | Supervisor | Agent | Branch Manager | Accountant | System Admin |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CRM/dashboard/customers | Allowed | Allowed | Allowed | Allowed | Restricted | Restricted | Restricted | Restricted |
| Sensitive customer data | Restricted | Allowed | Restricted | Restricted | Hidden | Restricted | Hidden | Approval Required |
| Customer finance | Restricted | Restricted | Restricted | Hidden | Hidden | Restricted | Allowed | Hidden |
| Classification/tags/segments | Approval Required | Allowed | Restricted | Restricted | Hidden | Restricted | Hidden | Hidden |
| Portal/identity review | Restricted | Allowed | Restricted | Approval Required | Hidden | Hidden | Hidden | Restricted |
| Loyalty/rules | Restricted | Allowed | Restricted | Restricted | Hidden | Restricted | Restricted | Hidden |
| Loyalty adjustment/approval | Approval Required | Approval Required | Hidden | Hidden | Hidden | Hidden | Approval Required | Hidden |
| Conversations | Restricted | Allowed | Allowed | Allowed | Allowed | Restricted | Hidden | Hidden |
| Complaints/ratings/follow-up | Restricted | Allowed | Allowed | Allowed | Restricted | Restricted | Hidden | Hidden |
| Individual in-app notice | Restricted | Allowed | Allowed | Allowed | Restricted | Restricted | Hidden | Hidden |
| Create/approve campaign | Approval Required | Allowed | Restricted | Hidden | Hidden | Restricted | Hidden | Hidden |
| Operations/timeline | Allowed | Restricted | Allowed | Allowed | Restricted | Allowed | Restricted | Hidden |
| Approve/reject web order | Restricted | Hidden | Allowed | Approval Required | Restricted | Restricted | Approval Required | Hidden |
| View payment proof | Hidden | Hidden | Restricted | Restricted | Hidden | Restricted | Allowed | Hidden |

All entries are **Proposed — Requires Business Approval** and still require least-privilege Backend enforcement and branch/assignment scope.

## 6. Admin Sitemap

| Route | Permission | Primary users | Sensitive data | Release | Current UI relationship |
| --- | --- | --- | --- | --- | --- |
| `/admin/crm` | VIEW_CRM_DASHBOARD | Management/CRM/CC managers | Aggregates | MVP | Extend current CRM dashboard |
| `/admin/crm/customers` | VIEW_CUSTOMERS | CRM/CC/branch | Phones/aggregates | MVP | Reuse current directory |
| `/admin/crm/customers/:customerId` | VIEW_CUSTOMERS + tabs | All scoped roles | Per tab | MVP | Reuse Customer 360 shell |
| `/admin/crm/segments` | MANAGE_CUSTOMER_CLASSIFICATION | CRM | Sensitive criteria/consent | Post-MVP | New module, reuse filters |
| `/admin/crm/portal-accounts` | MANAGE_PORTAL_ACCOUNTS | CRM reviewers | Identity/security | MVP | New tab/list |
| `/admin/crm/identity-reviews` | REVIEW_CUSTOMER_IDENTITY | Reviewers | Candidate PII | MVP | New queue |
| `/admin/crm/loyalty` | MANAGE_LOYALTY | CRM/finance | Ledger | Post-MVP | New governed module |
| `/admin/crm/loyalty/rules` | MANAGE_LOYALTY | CRM approvers | Rule economics | Post-MVP | New |
| `/admin/crm/loyalty/transactions` | MANAGE/ADJUST_LOYALTY | CRM/finance | Ledger/reasons | Post-MVP | New |
| `/admin/crm/conversations` | VIEW_CONVERSATIONS | CC/CRM | Message content | MVP | Converge call-center workspace |
| `/admin/crm/complaints` | VIEW_COMPLAINTS | CC/CRM | Complaints/images | MVP | Reuse current complaint views |
| `/admin/crm/ratings` | VIEW_COMPLAINTS | CX/CRM | Contact consent | MVP | New unified queue |
| `/admin/crm/occasions` | VIEW_CUSTOMERS | CRM/CC | Birth/family | Post-MVP | Reuse current occasions |
| `/admin/crm/notifications` | SEND_CUSTOMER_NOTIFICATION | CRM/ops | Audience/content | MVP | New |
| `/admin/crm/campaigns` | CREATE/APPROVE_MARKETING_CAMPAIGN | CRM approvers | Consent/segments | Post-MVP | New |
| `/admin/crm/orders` | VIEW_OPERATIONS | Operations | Payment/address | MVP | Reuse active-order views |
| `/admin/crm/web-orders` | APPROVE/REJECT_WEB_ORDERS | Authorized CC/finance | Proof/payment | MVP after E | New |
| `/admin/crm/reports` | Domain report permissions | Management | Aggregates/finance | Post-MVP | Link existing reports |

## 7. Non-Functional Requirements

- **Performance:** Server pagination/filtering, debounced search, lazy Customer 360 tabs, safe non-sensitive caching, visible last update; never load all customers/orders/messages.
- **Security:** Backend authorization is authoritative; opaque references; sensitive-action reverification; audited exports/actions; PII-redacted logs; CSV injection prevention; private scanned attachments; no UI-only security.
- **Usability:** RTL, large-screen efficiency, loading/empty/error/stale states, keyboard shortcuts for call center, saved filters, breadcrumbs, clear quick actions, confirmation for dangerous actions, and no color-only status.
- **Reliability:** Show stale synchronized data with timestamp; idempotent action/retry; explicit Pending/Failed/Needs Review; never lose orders/messages or silently accept partial processing.

## 8. Requirements Matrix

| ID | Requirement | Priority | Release | Backend | Admin | Website | Cloud | Sync | E Dependency |
| --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- |
| D-16 | CRM Dashboard | P0 | MVP | ✓ | ✓ | — | ✓ | ✓ | E-01,E-02,E-05,E-07,E-10 |
| D-17 | Directory | P0 | MVP | ✓ | ✓ | — | ✓ | ✓ | E-02,E-07,E-10 |
| D-18 | Classification/segments | P1 | MVP/Post | ✓ | ✓ | — | ✓ | ✓ | E-02,E-07,E-10 |
| D-19 | Portal identity review | P0 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-01,E-02,E-03,E-07,E-10 |
| D-20 | Customer 360 | P0 | MVP | ✓ | ✓ | — | ✓ | ✓ | E-01,E-02,E-05,E-07,E-10 |
| D-21 | Loyalty admin | P1 | Post-MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-05,E-06,E-07,E-08,E-10 |
| D-22 | Conversations | P0 | MVP | ✓ | ✓ | ✓ | ✓ | ✓ | E-01,E-06,E-07,E-10 |
| D-23 | CX/follow-up | P0 | MVP/Post | ✓ | ✓ | ✓ | ✓ | ✓ | E-01,E-05,E-06,E-07,E-10 |
| D-24 | Notifications/campaigns | P1 | MVP/Post | ✓ | ✓ | ✓ | ✓ | ✓ | E-06,E-07,E-10 |
| D-25 | Operations/web orders | P0 | MVP after E | ✓ | ✓ | ✓ | ✓ | ✓ | E-01..E-10 |

## 9. Sensitive Data Matrix

| Data | Who can view | Who can edit | Audit | Masking |
| --- | --- | --- | --- | --- |
| Phone/PII | Scoped CRM/CC | Authorized CRM/customer flow | Changes/exports | Role-based |
| Family/birth year/allergies | Explicit sensitive role | Customer/authorized CRM | Access/change | Hide year/details |
| Internal notes/risk/restriction reason | Authorized CRM managers | Privileged role | Every access/change | Hidden otherwise |
| Finance/spend/account | Finance + explicit grant | Finance workflow | Full | Totals hidden otherwise |
| Loyalty ledger/fraud reason | CRM loyalty/finance | Ledger actions only | Immutable | Customer-safe wording |
| Conversations/complaints/images | Assigned/support roles | Workflow roles | Sensitive access/actions | Public/internal split |
| Payment proof | Payment reviewer | Review annotation only | Every access/decision | Private signed access |
| Identity candidates/recovery | Identity reviewer | Controlled decision | Full before/after | Candidate PII masked |
| Audit log | Auditor/limited admin | Append by system only | Self-evident | PII minimized |

## 10. Alert Matrix

| Alert | Trigger | Severity | Audience | Required action |
| --- | --- | --- | --- | --- |
| Identity review | Multiple match/unsafe link | High | CRM reviewer | Resolve or keep limited |
| Unread/late conversation | Unread or SLA threshold | Medium/High | Agent/supervisor | Claim/respond/escalate |
| Complaint overdue | SLA exceeded | High | Owner/supervisor | Follow up/record action |
| Low rating | ≤3; urgent at 1–2 | Medium/High | CX queue | Contact-consent follow-up |
| Redemption review | Large/suspicious | High | Loyalty approver | Approve/reject reservation |
| Website payment review | Proof submitted/unverified | High | Authorized reviewer | Verify; never auto-confirm |
| Delayed order | Stage threshold | Medium/High | Operations owner | Open timeline/reason/action |
| Sync stale/failed | Freshness/retry exceeded | High | Operations/system role | Investigate/reconcile |

## 11. SLA Matrix

| Process | Normal | Yellow | Red | Owner | Paused outside hours |
| --- | --- | --- | --- | --- | --- |
| Normal conversation | <10m | 10–20m | >20m | Assigned agent | Yes, per schedule |
| Active-order conversation | <3m | 3–6m | >6m | Active-order queue | No/Configurable |
| Complaint acknowledgement | <30m | 30–60m | >60m | Complaint owner | Yes, per type |
| Urgent rating | <15m | 15–30m | >30m | CX owner | Configurable |
| Identity review | <4 business h | 4–8h | >8h | Identity reviewer | Yes |
| Overall order | <20m | 20–30m | >30m | Operations | Stage-specific |

All thresholds except the D1-approved normal conversation initial alert and the requested initial order bands are **Proposed — Requires Business Approval**; final stage/branch configuration remains required.

## 12. Reusable Components Matrix

| Existing component/service | Repository path | Reuse | Required changes | Risk |
| --- | --- | --- | --- | --- |
| CRM routes/shell | `o2-company-front/src/App.tsx`, `src/features/crm` | High | Extend routes/tabs/permissions | Duplicate call-center CRM |
| Customer 360/tabs | `o2-company-front/src/features/crm` | High | Lazy tabs and role actions | Sensitive overfetch |
| Call-center customer/workspace | `o2-company-front/src/components/call-center` | High | Embed shared 360/inbox | Divergent models |
| CRM API client | `o2-company-front/src/features/crm/api.ts` | High | Add governed endpoints | Multiple Axios clients |
| Auth/guards | `o2-company-front/src/auth`, `CrmRouteGuard` | Medium | New permissions; Backend enforcement | localStorage session risk |
| Tables/states/status chips | `DomainTable`, `CrmState`, `StatusChip` | High | Server filters/SLA semantics | Conflicting status mapping |
| Laravel identity services | `app/Services/CustomerIdentityService.php`, `PhoneNormalizer.php` | High | Portal mapping contract | Fragmented legacy phone |
| Laravel CRM queries/access | `Customer360QueryService`, `CrmCustomerAccessService` | High | Extend projection/scopes | Overbroad sensitive data |
| Order/payment services | `OrderConfirmationService`, `InvoiceFromOrderService`, `InvoicePaymentService` | Medium | Orchestrated idempotent web flow | Partial duplicate finance |

## 13. MVP Matrix

| Feature | MVP | Post-MVP | Future |
| --- | --- | --- | --- |
| Dashboard/directory/shared 360 | Core queues/search/tabs | Advanced analytics | Predictive recommendations |
| Classification | Manual governed axes/tags | Dynamic segments | ML scoring |
| Portal review | Limited/review/link/restrict | Device management | Advanced identity risk |
| Loyalty | Visibility/queue foundation | Ledger/rules/redemption/referrals | Cost optimization |
| Conversations | Text unified inbox/in-app | Capacity optimization | External channels/chatbot |
| Complaints/ratings | Queues/images/follow-up | Advanced root cause | Public review publishing |
| Occasions | Existing customer occasions | Family occasions/campaigns | Personalization engine |
| Notifications | Individual/in-app operational | In-app campaigns/segments | Push/WhatsApp/SMS/email |
| Operations/web orders | Monitoring and safe approval after E | Advanced SLA analytics | Predictive staffing |

## 14. End-to-End Scenarios

1. **Open customer:** normalized phone search → directory result → Customer 360 alerts → active order → start conversation.
2. **Identity review:** registration → multiple matches → limited account → review queue → select/reason/audited link → history/points enabled.
3. **Automatic redemption:** request → validate rule → idempotent ledger post → Admin record → customer in-app result.
4. **Approval redemption:** large request → reserve points → approval queue → approve/reject → ledger/result.
5. **Active-order message:** customer message → unified urgent queue → claim/auto-assign → response → in-app notification.
6. **Low rating:** 1 star → urgent queue → contact consent → follow-up → conversation/complaint → resolution.
7. **Website order:** order/proof → private review → transactional approval → production/timeline → customer tracking.
8. **Delayed order:** stage threshold → yellow/red text/icon → manager timeline → owner/department → reason/action recorded.

## 15. Final Business Review Questions

### A. CRM Dashboard

- **CRM-DASH-01:** Default dashboard scope: A) employee’s branches/today, B) all branches/today, C) last selected. **Recommendation:** A.
- **CRM-DASH-02:** Operational aggregate refresh: A) 1 minute, B) 5 minutes, C) 15 minutes. **Recommendation:** B.

### B. Customer Directory and Classification

- **CRM-CUST-01:** Default page size: A) 25, B) 50, C) 100. **Recommendation:** A.
- **CRM-CUST-02:** `undesirable`/`blocked` approval: A) CRM Manager, B) two-person approval, C) General Management. **Recommendation:** A, with two-person approval for high-impact cases.

### C. Portal Account Review

- **CRM-ID-01:** Identity review target: A) 1 hour, B) 4 business hours, C) 1 business day. **Recommendation:** B.
- **CRM-ID-02:** Unlink/recovery with history: A) one reviewer, B) two-person approval, C) General Management only. **Recommendation:** B.

### D. Customer 360 and Permissions

- **CRM-360-01:** Default tabs for agents: A) Overview/Orders/Addresses/Conversations, B) all non-financial, C) Overview only. **Recommendation:** A.
- **CRM-360-02:** Sensitive-tab access audit: A) every open, B) only export/change, C) sampled. **Recommendation:** A for finance/proof/identity; B for other tabs.

### E. Loyalty

- **CRM-LOY-01:** Large redemption threshold: A) fixed points, B) percentage of available balance, C) rule-specific. **Recommendation:** C.
- **CRM-LOY-02:** High-value adjustment approval: A) CRM Manager, B) CRM + Accountant, C) General Management. **Recommendation:** B.

### F. Conversations and SLA

- **CRM-MSG-01:** Active-order initial alert: A) 2 minutes, B) 3 minutes, C) 5 minutes. **Recommendation:** B.
- **CRM-MSG-02:** Auto-assignment delay: A) immediate, B) 2 minutes unclaimed, C) 5 minutes unclaimed. **Recommendation:** B.

### G. Complaints, Ratings and Occasions

- **CRM-CX-01:** Standard complaint acknowledgement: A) 15 minutes, B) 30 minutes, C) 60 minutes. **Recommendation:** B.
- **CRM-CX-02:** A ≤3-star rating creates: A) follow-up only, B) complaint automatically, C) follow-up then agent decides complaint. **Recommendation:** C.

### H. Notifications and Campaigns

- **CRM-CAMP-01:** Bulk marketing approval threshold: A) every campaign, B) >100 recipients, C) >500 recipients. **Recommendation:** B.
- **CRM-CAMP-02:** Shared saved segments: A) CRM Manager only, B) all campaign creators, C) General Management only. **Recommendation:** A.

### I. Operations and Website Orders

- **OPS-01:** Website-order approval ownership: A) call-center supervisor, B) branch manager, C) call center approves order and authorized finance confirms payment. **Recommendation:** C.
- **OPS-02:** Overall SLA bands: A) retain <20/20–30/>30, B) branch-specific only, C) order-type-specific only. **Recommendation:** A initially, then allow branch/stage overrides.

## 16. Architecture Traceability

| D2 Requirement | Canonical E decisions and supporting topics required |
| --- | --- |
| D-16 | E-01,E-02,E-07,E-08,E-09; EA-02 |
| D-17 | E-07,E-09; EA-06 |
| D-18 | E-08,E-09; EA-06 |
| D-19 | E-01,E-07,E-08,E-09; EA-01,EA-06 |
| D-20 | E-01,E-02,E-07,E-09; EA-02,EA-06 |
| D-21 | E-07,E-08,E-10; EA-03,EA-05,EA-06 |
| D-22 | E-01,E-02,E-03,E-07,E-09,E-10; EA-03,EA-06 |
| D-23 | E-01,E-02,E-03,E-04,E-07,E-09,E-10; EA-02,EA-03,EA-06 |
| D-24 | E-02,E-04,E-07,E-09,E-10; EA-03,EA-06 |
| D-25 | E-01,E-02,E-05,E-06,E-07,E-08,E-09,E-10; EA-02,EA-03,EA-04,EA-05 |

No E or EA decision is resolved by D2.
