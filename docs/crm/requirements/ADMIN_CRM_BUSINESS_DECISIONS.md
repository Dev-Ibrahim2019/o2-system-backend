# Admin CRM Business Decisions — D2 Approval

Status: **Business Approved — Awaiting Architecture Decisions**  
Scope: D-16 through D-25. These decisions approve business behavior only; they authorize no implementation, schema, API, route, permission, or Phase E decision.

## CRM Dashboard

### BD-D2-001 — Attention-first dashboard
**Decision ID:** BD-D2-001  
**Related Requirement:** D-16  
**Title:** Attention-first dashboard ordering  
**Decision:** Show urgent work queues before indicators, charts, and analytics. Keep urgent lists separate from analytical visuals.  
**Rationale:** Operational action must be visible before analysis.  
**Release:** MVP  
**Roles:** General Management, CRM Manager, Call Center Manager, Branch Manager  
**Approval Requirements:** Business approved; no per-use approval.  
**Audit Requirements:** Audit sensitive drill-down, assignment, and disposition.  
**Architecture Dependencies:** E-01,E-02,E-07,E-08; EA-02.

### BD-D2-002 — Authorized branch scope and freshness
**Decision ID:** BD-D2-002  
**Related Requirement:** D-16  
**Title:** Authorized branch scope and freshness  
**Decision:** Show only authorized branches; provide `All Authorized Branches` when more than one is allowed. Target event-driven refresh with a five-minute fallback, and display last-update time and stale-data state.  
**Rationale:** Preserve least privilege and make data freshness explicit.  
**Release:** MVP  
**Roles:** Dashboard users  
**Approval Requirements:** Access remains permission and branch scoped.  
**Audit Requirements:** Audit sensitive access and alert actions.  
**Architecture Dependencies:** E-02,E-07; implementation method remains Pending E.

## Customer Directory

### BD-D2-003 — Server-side directory
**Decision ID:** BD-D2-003  
**Related Requirement:** D-17  
**Title:** Directory pagination and query model  
**Decision:** Default to 25 customers with 50 and 100 options; pagination, search, filters, and sorting are server-side. Never load the full customer population into the UI.  
**Rationale:** Protect performance and data exposure at scale.  
**Release:** MVP  
**Roles:** Authorized CRM, call-center, branch, finance, and administration users  
**Approval Requirements:** None beyond access permission.  
**Audit Requirements:** Audit exports and sensitive access.  
**Architecture Dependencies:** E-09.

### BD-D2-004 — Directory scope and field visibility
**Decision ID:** BD-D2-004  
**Related Requirement:** D-17  
**Title:** Order sources and sensitive totals  
**Decision:** Include call-center, POS/immediate, family-hall/hospitality, website, and future approved sources; filter by branch, source, order type, and period. Call-center roles may see order count; spending totals require authorized administrative or financial roles. Authorized CRM users may see the full phone.  
**Rationale:** Provide one operational directory without leaking financial detail.  
**Release:** MVP  
**Roles:** CRM, call center, branch management, authorized finance/administration  
**Approval Requirements:** Field-level authorization.  
**Audit Requirements:** Audit sensitive access and export reason, criteria, and count.  
**Architecture Dependencies:** E-09; EA-06.

## Classifications and Segments

### BD-D2-005 — Governed classification
**Decision ID:** BD-D2-005  
**Related Requirement:** D-18  
**Title:** Automatic and human classification  
**Decision:** Rules may apply computed classifications such as VIP and at-risk automatically. Sensitive classifications require human decisions. `undesirable` requires employee proposal, mandatory reason, and manager approval; `blocked` requires mandatory reason, CRM Manager approval, and General Manager approval.  
**Rationale:** Combine scalable segmentation with proportional control.  
**Release:** MVP  
**Roles:** CRM staff, CRM Manager, General Manager  
**Approval Requirements:** As stated by classification severity.  
**Audit Requirements:** Immutable before/after, reason, source/rule, actor, and approvers.  
**Architecture Dependencies:** E-08,E-09; EA-06.

### BD-D2-006 — Segments, ownership, and history
**Decision ID:** BD-D2-006  
**Related Requirement:** D-18  
**Title:** Segment types and permanent owner  
**Decision:** Support dynamic and manual segments. Permanent customer ownership is limited to VIP, company, and active follow-up cases. Preserve classification, tag, segment, and owner change history.  
**Rationale:** Avoid unnecessary ownership while retaining accountability.  
**Release:** MVP classifications; Post-MVP dynamic campaigns  
**Roles:** CRM Manager and authorized supervisors  
**Approval Requirements:** Shared/marketing use remains governed.  
**Audit Requirements:** Full membership and ownership history.  
**Architecture Dependencies:** E-08; EA-06.

## Portal Accounts and Identity Review

### BD-D2-007 — Identity review workflow
**Decision ID:** BD-D2-007  
**Related Requirement:** D-19  
**Title:** Review ownership and service target  
**Decision:** A Call Center Agent reviews and proposes; a manager approves. Target completion is within one business day, and the account remains Limited during review.  
**Rationale:** Balance customer access with controlled identity resolution.  
**Release:** MVP  
**Roles:** Call Center Agent, CRM Manager  
**Approval Requirements:** Manager approval.  
**Audit Requirements:** Case access, evidence, proposal, approval, and outcome.  
**Architecture Dependencies:** E-09; EA-01,EA-06.

### BD-D2-008 — Link, unlink, and merge controls
**Decision ID:** BD-D2-008  
**Related Requirement:** D-19  
**Title:** Identity operation approvals  
**Decision:** Merge without financial data requires CRM Manager approval. Merge involving orders, invoices, payments, or receivables requires CRM Manager and Accountant approval. Unlink requires a second approval. Every link, unlink, and merge requires a reason and audit; merge is never executed inside linking.  
**Rationale:** Prevent identity changes from silently rewriting operational or financial history.  
**Release:** MVP linking; merge implementation later  
**Roles:** Identity reviewer, CRM Manager, Accountant  
**Approval Requirements:** As stated by data impact.  
**Audit Requirements:** Full before/after, evidence, reason, actors, and approvals.  
**Architecture Dependencies:** E-08,E-09; EA-01,EA-05.

## Customer 360

### BD-D2-009 — One reusable Customer 360
**Decision ID:** BD-D2-009  
**Related Requirement:** D-20  
**Title:** Shared Customer 360 behavior  
**Decision:** Use one reusable Customer 360 and one underlying customer record. Administration and call center use the same data, with presentation and actions varying by context and permission. Show an active order first; otherwise open Overview. Overview summarizes recent orders and Orders uses pagination for complete cross-source history.  
**Rationale:** Eliminate competing profiles and preserve contextual efficiency.  
**Release:** MVP  
**Roles:** All authorized customer-facing and management roles  
**Approval Requirements:** Tab/action permissions.  
**Audit Requirements:** Sensitive tab access, export, and action audit.  
**Architecture Dependencies:** E-01,E-02,E-09; EA-02,EA-06.

### BD-D2-010 — Customer 360 privacy and actions
**Decision ID:** BD-D2-010  
**Related Requirement:** D-20  
**Title:** Sensitive display and action governance  
**Decision:** Separate computed favorites from manually selected favorites and classify internal notes by sensitivity. Non-financial users see only a balance warning; call-center agents do not see detailed debt by default. Sensitive actions require reason and action-specific approval.  
**Rationale:** Expose actionable context without unnecessary financial or internal detail.  
**Release:** MVP  
**Roles:** CRM, call center, finance, branch, administration  
**Approval Requirements:** Action-specific and role-based.  
**Audit Requirements:** Sensitive access and every privileged action.  
**Architecture Dependencies:** EA-05,EA-06.

## Loyalty and Referrals

### BD-D2-011 — Loyalty rule governance
**Decision ID:** BD-D2-011  
**Related Requirement:** D-21  
**Title:** Loyalty rule approval and versioning  
**Decision:** A specialist creates, CRM Manager approves, and high-cost rules require General Manager approval. Each rule declares `editable`, `limited_edit`, `version_required`, or `immutable`; entitlement-impacting changes create a new version and prior transactions remain tied to their original version.  
**Rationale:** Preserve customer rights and explainable reward economics.  
**Release:** Post-MVP  
**Roles:** Loyalty Specialist, CRM Manager, General Manager  
**Approval Requirements:** Manager and cost-based escalation.  
**Audit Requirements:** Version, diff, creator, approvals, and effective dates.  
**Architecture Dependencies:** E-08,E-10; EA-03,EA-05.

### BD-D2-012 — Points adjustments and expiry
**Decision ID:** BD-D2-012  
**Related Requirement:** D-21  
**Title:** Ledger-only adjustment and configurable expiry  
**Decision:** Within-limit adjustments require CRM Manager and reason; above-limit adjustments require General Manager or Finance. Every adjustment is a ledger entry and direct balance editing is prohibited. Each rule may specify no expiry, duration from earning, or fixed expiry date.  
**Rationale:** Treat loyalty value as auditable financial-like evidence.  
**Release:** Post-MVP  
**Roles:** CRM Manager, General Manager, Finance  
**Approval Requirements:** Threshold-based.  
**Audit Requirements:** Immutable ledger entry, reason, actor, approver, and source reference.  
**Architecture Dependencies:** E-07,E-08,E-10; EA-03,EA-05.

## Conversations and SLA

### BD-D2-013 — Conversation assignment order
**Decision ID:** BD-D2-013  
**Related Requirement:** D-22  
**Title:** Governed assignment precedence  
**Decision:** Assign by branch, then conversation type, employee specialization, active shift and availability, capacity, and least busy. Capacity is configurable by employee, role, and shift; unassignable work remains Unassigned and visible to supervisors.  
**Rationale:** Route work consistently while retaining an explicit fallback queue.  
**Release:** MVP  
**Roles:** Agents, supervisors, call-center and CRM managers  
**Approval Requirements:** Configuration governance only.  
**Audit Requirements:** Assignment, transfer, claim, and escalation history.  
**Architecture Dependencies:** E-03,E-07,E-09; EA-03.

### BD-D2-014 — Supervisor conversation authority
**Decision ID:** BD-D2-014  
**Related Requirement:** D-22  
**Title:** Supervisor visibility and intervention  
**Decision:** Supervisors see all team conversations and may reply, intervene, reassign, or escalate. Every intervention is recorded under the supervisor's identity.  
**Rationale:** Preserve operational oversight and individual accountability.  
**Release:** MVP  
**Roles:** Supervisor, Call Center Manager  
**Approval Requirements:** Supervisor permission.  
**Audit Requirements:** Actor-attributed content and workflow transitions.  
**Architecture Dependencies:** E-03; EA-06.

## Complaints, Ratings and Occasions

### BD-D2-015 — Complaint intake and acknowledgement
**Decision ID:** BD-D2-015  
**Related Requirement:** D-23  
**Title:** Central complaint ownership and acknowledgement  
**Decision:** Central CRM receives, an employee claims, and the complaint is assigned to a branch/department while central oversight remains active. System Receipt is immediate and distinct from Human Acknowledgement. Standard complaints require human acknowledgement within 30 minutes; active-order complaints use a shorter configurable target and approved business calendars.  
**Rationale:** Separate technical receipt from accountable human response.  
**Release:** MVP  
**Roles:** CRM, call center, branch/department owners  
**Approval Requirements:** None for claim; configuration is governed.  
**Audit Requirements:** Receipt, acknowledgement, assignment, SLA, and escalation events.  
**Architecture Dependencies:** E-02,E-03,E-07; EA-02.

### BD-D2-016 — Compensation controls
**Decision ID:** BD-D2-016  
**Related Requirement:** D-23  
**Title:** Role-based complaint compensation  
**Decision:** Compensation authority and limits vary by role; Call Center Manager or CRM Manager may approve within limits, and monetary compensation is subject to Finance oversight. Every compensation links to complaint, order, and approver and must be duplicate-safe.  
**Rationale:** Enable recovery without uncontrolled financial exposure.  
**Release:** MVP policy; financial implementation later  
**Roles:** Call Center Manager, CRM Manager, Finance  
**Approval Requirements:** Limit and type based.  
**Audit Requirements:** Amount/value, reason, source references, actors, approvals, and deduplication reference.  
**Architecture Dependencies:** E-08,E-10; EA-03,EA-05.

### BD-D2-017 — Urgent ratings and occasions
**Decision ID:** BD-D2-017  
**Related Requirement:** D-23  
**Title:** Follow-up and occasion consent  
**Decision:** Urgent ratings assign a follow-up employee and notify branch manager and central CRM. Employees may add occasions only with information source, consent, and purpose; marketing permission is separate from storing the occasion.  
**Rationale:** Enable service recovery and relationship context without conflating consent.  
**Release:** MVP ratings; Post-MVP occasions  
**Roles:** CRM, call center, branch management  
**Approval Requirements:** Appropriate consent and permission.  
**Audit Requirements:** Assignment/notifications and occasion source/consent/purpose.  
**Architecture Dependencies:** E-04,E-09; EA-06.

## Notifications and Campaigns

### BD-D2-018 — Campaign approval workflow
**Decision ID:** BD-D2-018  
**Related Requirement:** D-24  
**Title:** Risk-based campaign approval  
**Decision:** Employee creates and CRM Manager approves; large/high-cost campaigns also require General Manager approval. Large-campaign policy considers configurable recipient count, projected cost, offer value, discount, branch scope, segment sensitivity, frequency, and financial impact—not count alone.  
**Rationale:** Match approval depth to campaign risk and cost.  
**Release:** Post-MVP campaigns  
**Roles:** Campaign creator, CRM Manager, General Manager  
**Approval Requirements:** Risk-based escalation.  
**Audit Requirements:** Audience snapshot, factors, content, schedule, creator, and approvals.  
**Architecture Dependencies:** E-04,E-07,E-10; EA-03,EA-05,EA-06.

### BD-D2-019 — Branch and individual messaging
**Decision ID:** BD-D2-019  
**Related Requirement:** D-24  
**Title:** Scoped campaigns and operational templates  
**Decision:** Branch managers may create campaigns only for customers of authorized branches and need CRM Manager approval. Call-center agents may use approved operational templates only and may not send free-form marketing through individual notifications. Every notification is audited.  
**Rationale:** Preserve scope, consent, and message governance.  
**Release:** MVP operational notices; Post-MVP campaigns  
**Roles:** Branch Manager, Call Center Agent, CRM Manager  
**Approval Requirements:** CRM Manager for branch campaigns; approved templates for agents.  
**Audit Requirements:** Actor, recipient/reference, template/content version, result, and time.  
**Architecture Dependencies:** E-04,E-09; EA-06.

## Operations and Website Orders

### BD-D2-020 — Operational SLA bands and evidence
**Decision ID:** BD-D2-020  
**Related Requirement:** D-25  
**Title:** Configurable operational SLA  
**Decision:** Use `<20 minutes = normal`, `20–30 = yellow`, and `>30 = red` as general bands, configurable by stage, branch, order type, source, and operating period. The system records transitions, stage start/end, elapsed time, responsible employee/department, and threshold breach. Employees record operational reasons; red cases require supervisor review.  
**Rationale:** Establish a common baseline while preserving contextual accountability.  
**Release:** MVP  
**Roles:** Operations staff, supervisors, managers  
**Approval Requirements:** Supervisor review for red cases.  
**Audit Requirements:** Immutable timeline, reason, review, actor, and configuration version.  
**Architecture Dependencies:** E-02,E-07,E-08; EA-02.

### BD-D2-021 — Contextual employee ranking
**Decision ID:** BD-D2-021  
**Related Requirement:** D-25  
**Title:** Internal contextual ranking  
**Decision:** Employee ranking is manager-only, is not visible to all staff, and cannot alone determine punishment or evaluation. It accounts for context, outages, shift, and workload.  
**Rationale:** Prevent misleading or punitive use of raw operational metrics.  
**Release:** Post-MVP analytics  
**Roles:** Authorized managers  
**Approval Requirements:** Restricted management access.  
**Audit Requirements:** Access and configuration changes.  
**Architecture Dependencies:** E-02,E-08; EA-02.

### BD-D2-022 — Separate payment verification and order approval
**Decision ID:** BD-D2-022  
**Related Requirement:** D-25  
**Title:** Website payment and approval duties  
**Decision:** An authorized call-center employee may review proof, confirm payment, and approve the order under the current operating model, but Payment Verification and Order Approval remain logically separate operations with separate permissions, actor, timestamp, result, audit event, reference, and evidence.  
**Rationale:** Preserve separation of duties even when one user holds both authorities.  
**Release:** MVP after E  
**Roles:** Authorized Call Center Agent, financial reviewer  
**Approval Requirements:** Independent permissions for both operations.  
**Audit Requirements:** Complete evidence and outcome for each operation.  
**Architecture Dependencies:** E-05,E-06,E-10; EA-03,EA-05.

### BD-D2-023 — Payment-proof safeguards
**Decision ID:** BD-D2-023  
**Related Requirement:** D-25  
**Title:** Payment verification safeguards  
**Decision:** External payment references are unique; proof is private; production cannot start before confirmation; duplicate execution is prohibited; confirmer and approver are recorded. Suspicious/mismatched cases enter Needs Review. Refund/reversal uses separate permission, and direct status edits cannot bypass workflow.  
**Rationale:** Protect financial integrity and prevent premature fulfillment.  
**Release:** MVP after E  
**Roles:** Payment reviewer, order approver, finance  
**Approval Requirements:** Operation-specific permissions and review on mismatch.  
**Audit Requirements:** Proof access, evidence, external reference, decisions, reversals, and workflow transitions.  
**Architecture Dependencies:** E-05,E-06,E-07,E-08,E-10; EA-03,EA-05.

### BD-D2-024 — Price-difference approval
**Decision ID:** BD-D2-024  
**Related Requirement:** D-25  
**Title:** Repricing and customer consent  
**Decision:** Prices should not normally differ. If they do, reprice, calculate the difference, request and record customer approval, then approve the order. Never silently accept a new price, trust an old price without verification, or start production before difference approval.  
**Rationale:** Prevent billing disputes and unapproved fulfillment.  
**Release:** MVP after E  
**Roles:** Order reviewer, customer  
**Approval Requirements:** Recorded customer approval.  
**Audit Requirements:** Old/new price, difference, catalog reference, customer approval, actor, and time.  
**Architecture Dependencies:** E-05,E-08; EA-04.

### BD-D2-025 — Safe approval recovery
**Decision ID:** BD-D2-025  
**Related Requirement:** D-25  
**Title:** Idempotent retry and reconciliation  
**Decision:** Failed website-order approval follows safe retry, idempotent reconciliation, and Needs Review when unresolved. Preserve correlation reference and last successful step; prevent duplicate order, invoice, payment, production ticket, partial silent success, and orphan financial records.  
**Rationale:** Make cross-system failure recoverable without duplication.  
**Release:** MVP after E  
**Roles:** Authorized reviewers, operations, finance, system integration  
**Approval Requirements:** Manual review when automated reconciliation cannot prove outcome.  
**Audit Requirements:** Every attempt, step, result, correlation, error, and reconciliation decision.  
**Architecture Dependencies:** E-07,E-08,E-10; EA-03,EA-05.

### BD-D2-026 — Website-order rejection
**Decision ID:** BD-D2-026  
**Related Requirement:** D-25  
**Title:** Authorized and financially safe rejection  
**Decision:** Any call-center employee holding `REJECT_WEB_ORDERS` may reject. Record internal reason, public customer reason, optional internal note, employee, timestamp, payment status, and external order reference. Confirmed or possibly received payment moves to financial review/settlement rather than simple rejection.  
**Rationale:** Allow timely rejection without abandoning payment obligations.  
**Release:** MVP after E  
**Roles:** Authorized Call Center Employee, Finance  
**Approval Requirements:** `REJECT_WEB_ORDERS`; financial review when payment may exist.  
**Audit Requirements:** Full rejection and settlement trail.  
**Architecture Dependencies:** E-06,E-08,E-10; EA-05.
