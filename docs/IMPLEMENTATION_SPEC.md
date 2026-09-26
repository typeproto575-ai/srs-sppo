# Parish Sacramental Records and Certificate Request System — Software Requirements Specification

**St. Patrick's Parish Office, Dingalan, Aurora — Capstone Project**

**Technology:** Plain PHP, HTML, CSS, JavaScript, and MySQL/MariaDB running through XAMPP.

**Document purpose:** This specification defines the approved operating model, user roles, workflows, security controls, and audit requirements for the parish system. It reflects the parish secretary's clarification that requests and applications are handled onsite. The website provides public information and tracking for secretary-created transactions; it is not a parishioner account portal.

---

## 1. System Overview

The Parish Sacramental Records and Certificate Request System (SRS-SPPO) is a secretary-operated digital index and workflow system for parish sacramental records. It supports Baptism, Confirmation, First Holy Communion, Holy Matrimony, and the parish's approved related records.

The system digitizes record retrieval, request processing, requirements monitoring, scheduling, certificate preparation, printing, pickup, release, reporting, and accountability. The physical parish logbooks remain the canonical records. Digital records must retain the physical register references, including book, page, and line numbers where applicable.

The public website is informational. Parishioners do not create accounts and do not submit certificate requests or sacrament applications directly through the website. A parishioner visits the parish office, where the secretary creates the transaction. If physical requirements cannot be delivered, the parishioner may send them through the parish's official Gmail account. Gmail review remains an office procedure; sending an email does not automatically create a system transaction.

The system contains no payment module. Payment, fees, amounts, receipts, and financial records are outside the software's scope and must not be stored or processed by this system.

---

## 2. Goals and Scope

### 2.1 Goals

The system shall:

1. Provide the secretary with a reliable workspace for encoding and managing onsite requests and applications.
2. Provide fast, searchable access to digitized sacramental records while preserving physical-register references.
3. Support certificate processing, printing, finalization, notification, pickup, and release tracking.
4. Track requirements received physically or through the official parish Gmail.
5. Provide public information pages for parish procedures and documentary requirements.
6. Provide optional public status tracking for reference numbers issued by the parish office, without requiring a parishioner account.
7. Preserve an append-only audit history of security, account, record, certificate, and workflow actions.
8. Ensure the parish can recover from secretary turnover, forgotten passwords, and staff changes through a protected Master Account.

### 2.2 Out of scope

The system shall not provide:

- Parishioner registration or parishioner login accounts.
- Public online certificate-request submission.
- Public online sacrament-application submission.
- Public document-upload forms.
- Online payment, payment verification, fee tracking, currency fields, or financial reports.
- Digital download of official certificates by parishioners.
- A priest login for routine canonical approval. The priest's offline confirmation is recorded by the secretary as data and notes.
- Phone-number collection when the official parish forms specify email as the contact method.

---

## 3. User Roles and Access Control

The system shall use individual accounts. Users must not share credentials because the audit trail must identify the person who performed each action.

### 3.1 Parish Administrator / Master Account

The **Parish Administrator** is the system's Master Account role. The account is normally assigned to the parish priest or another formally designated parish authority. It exists to preserve system continuity when a secretary leaves, forgets a password, or becomes unavailable.

The Parish Administrator shall have full system access, including:

- All sacramental records and person profiles.
- All certificate requests and sacrament applications.
- Requirements status and review notes.
- Certificate generation, printing, finalization, pickup, and release history.
- Scheduling, announcements, reports, backups, and system settings.
- All security, account-management, and audit-log pages.
- Creation of new secretary accounts.
- Password reset for secretary accounts.
- Activation and deactivation of secretary accounts.
- Restoration of access after secretary turnover.
- Export and review of audit logs.
- Transfer of the Master Account role to a new parish priest or authorized administrator.

The Master Account shall not be permanently removable through the normal application interface. The last active Master Account shall not be deleted, deactivated, or demoted unless another active Master Account is assigned in the same controlled operation.

A Master Account transfer shall require explicit confirmation and shall create an immutable audit entry containing the previous master, new master, initiating user, date, time, and reason.

### 3.2 Secretary Account

The Secretary account is the operational role. A secretary shall be able to:

- Encode onsite certificate requests.
- Encode onsite Baptism and Marriage applications.
- Search, view, and update approved record fields.
- Record whether requirements were received physically, through Gmail, or through both channels.
- Record requirements as not received, received, under review, incomplete, or verified.
- Process certificate requests and match requests to register records.
- Generate, print, finalize, and release certificates according to the workflow.
- Manage schedules and announcements.
- View operational request and record history.
- Change the secretary's own password and log out.

A Secretary shall not be able to:

- Delete, deactivate, demote, or change the password of the last Master Account.
- Create or assign another Master Account.
- Transfer the Master Account role.
- Delete or edit audit-log entries.
- Remove audit history.
- Change security settings reserved for the Parish Administrator.
- Permanently delete historical records through ordinary workflow actions.

Secretaries may view operational history needed for their work. Sensitive security events relating to Master Accounts, role transfers, and security configuration shall be restricted to the Parish Administrator.

### 3.3 Parishioner / Public Visitor

A parishioner or public visitor has no system account. Public visitors may:

- View parish information, office hours, and location.
- View sacrament and certificate requirements.
- Read instructions for onsite requests and Gmail-based requirements submission.
- Track an existing secretary-created transaction using the reference number issued by the parish office and the email recorded for that transaction, if public tracking is enabled.

A public visitor may not access records, create a transaction directly, upload documents to the website, generate a certificate, or access an administrative page.

### 3.4 Account lifecycle

Secretary accounts shall be deactivated rather than permanently deleted when a staff member leaves. Deactivation preserves the identity attached to historical audit records. The Parish Administrator may later reactivate an account or create a replacement account.

The first login using a temporary password shall require a password change. Passwords shall be stored using a current password-hashing algorithm and shall never appear in logs or source-code comments.

---

## 4. Operating Workflows

### 4.1 Certificate request workflow

Certificate requests are created by the secretary after an onsite request is accepted for processing. A parishioner may send requirements through the official Gmail when physical submission is not possible, but this does not itself create a request.

The approved workflow is:

```text
Parishioner visits the parish office
        ↓
Secretary receives the request and reviews available requirements
        ↓
Secretary handles any offline parish procedure outside the system
        ↓
Secretary encodes the certificate request
        ↓
System generates a reference number
        ↓
Secretary searches and verifies the physical parish register
        ↓
Request is processed
        ↓
Certificate is prepared for printing
        ↓
Certificate is printed and finalized
        ↓
Status becomes Ready for Pickup
        ↓
Parishioner is notified through the approved email procedure
        ↓
Certificate is released onsite after identity verification
```

The system shall not record payment, fee, amount, receipt, or payment-confirmation data. The request workflow shall not include a payment status.

Recommended certificate request statuses are:

| Status | Meaning |
|---|---|
| `received` | The secretary encoded the accepted onsite request. |
| `processing` | The secretary is locating and verifying the parish register record. |
| `for_printing` | The record has been matched and the certificate may be printed. |
| `finalizing` | The certificate has been printed and is being checked, signed, or sealed. |
| `ready_for_pickup` | The finalized certificate is ready at the parish office. |
| `released` | The certificate was physically released to the verified claimant. |
| `correction_needed` | The request or supplied information needs clarification. |
| `unable_to_locate_record` | The record could not currently be located in the parish register. |
| `cancelled` | Processing was stopped with a recorded reason. |

Legacy database values may be retained during migration, but the user interface shall use the approved meanings above. If an existing `approved` value is retained for compatibility, it shall mean that the record was verified and approved for certificate generation, not that the certificate was released.

### 4.2 Sacrament application workflow

Baptism and Marriage applications are encoded by the secretary after an onsite interview or after the secretary reviews requirements received through the official Gmail. Confirmation and First Holy Communion are handled according to the parish's approved record-status process rather than through new public application forms.

The application workflow shall record:

- Applicant or subject information.
- Email address or email addresses required by the official parish forms.
- Requirements source: physical submission, official Gmail, or both.
- Requirements status: not received, received, under review, incomplete, or verified.
- Missing-requirement notes.
- Interview, seminar, banns, clearance, and schedule information where applicable.
- Secretary notes and status history.

Submitting requirements through Gmail shall not automatically create an application. The secretary must review the email and encode the application.

### 4.3 Certificate pickup and release

When a certificate is ready, the secretary shall mark it `ready_for_pickup` and record the notification action. The final certificate shall not be downloadable by a parishioner.

At pickup, the secretary shall verify the claimant in person, confirm the correct request, and then mark the request `released`. The system shall record the release date, time, staff account, and relevant claimant note without storing a copy of an identity document.

### 4.4 Requirements sent through Gmail

The official Gmail inbox remains outside the application. The system shall store only workflow metadata, such as:

- Submission source: physical, Gmail, or both.
- Requirements status.
- Date reviewed.
- Secretary who reviewed the requirements.
- Missing-document or correction notes.

The application shall not silently copy Gmail attachments into public web storage. Any future document-storage feature requires a separate privacy and retention design.

---

## 5. Public Website Requirements

The public website shall function as an information and guidance site. It shall include:

- Parish name, location, office hours, and official email address.
- Sacrament and certificate requirements.
- Instructions to visit the parish office for requests and applications.
- Instructions for sending requirements through official Gmail when physical submission is impossible.
- A warning that sending an email does not automatically create a request or application.
- Tracking instructions for reference numbers issued by the parish office, if tracking is retained.
- A Secretary / Admin Login link that leads to the protected administrative login page.

Public pages shall not present online certificate-request or sacrament-application submission as the normal parish workflow. If legacy form routes remain temporarily for compatibility, they shall be disabled or converted to informational pages before production use.

Public tracking shall require both the reference number and the email recorded by the secretary. It shall disclose only minimal information: transaction type, date recorded, current stage, and general instructions. It shall not reveal sensitive sacramental details, full family information, or internal notes.

Contact information collected by the system shall be email-only unless the parish formally approves a change to its official forms and privacy policy.

---

## 6. Functional Modules

### 6.1 Sacramental records

The system shall support searchable and filterable records for Baptism, Confirmation, First Holy Communion, Marriage, and the parish-approved Death/Burial status record. Records shall retain physical register references where applicable.

The secretary shall be able to create, view, search, filter, update, archive, and restore records according to role permissions. Permanent deletion shall not be the normal correction method.

### 6.2 Person and family identity management

The system should support a shared person index to reduce duplicate records. Before creating a new person, the secretary should be shown possible matches based on name, birthdate, parents, and other approved identifying details. The secretary must review possible duplicates before linking or creating a record.

### 6.3 Certificate generation

Certificates shall be generated only from an existing, matched parish record. The printable template shall contain the parish information, certificate type, approved non-confidential sacramental data, register references where appropriate, issuance date, signature area, and seal area.

The certificate lifecycle shall record generation, printing, finalization, reprinting, cancellation, pickup readiness, and release. Parishioners shall not download official certificates through the public website.

### 6.4 Scheduling

The secretary shall be able to manage baptisms, marriages, interviews, seminars, appointments, certificate pickup activities, and other parish events. The system shall warn about overlapping events while leaving final conflict resolution to the secretary.

### 6.5 Reports and backup

Reports shall support approved operational summaries without exposing restricted fields. Database backups shall be restricted to the Parish Administrator and authorized secretary accounts as approved by the parish. Backup files shall not be stored in a publicly accessible web directory.

---

## 7. Audit Logging and Accountability

### 7.1 Audit-log principle

The system shall maintain an append-only audit log. An audit entry, once written, shall not be editable or deletable through the application interface. The Parish Administrator shall have complete viewing, searching, filtering, and export access, but shall not silently alter or remove historical entries.

This protects the reliability of the history when investigating mistakes, staff turnover, unauthorized access, or record corrections.

### 7.2 Events that shall be logged

The system shall log the following events where applicable:

**Security and account events:** successful login, failed login, logout, session timeout, password change, password reset, secretary account creation, account deactivation, account reactivation, role change, Master Account transfer, security-setting change, and rate-limited access attempts.

**Workflow events:** certificate request encoded, sacrament application encoded, requirements marked received, requirements marked incomplete, requirements marked verified, request or application status changed, record matched, certificate generated, certificate printed, certificate finalized, certificate marked ready for pickup, notification attempted, notification failed, notification resent, certificate released, certificate reprinted, certificate cancelled, schedule created or changed, and announcement created or changed.

**Record events:** record created, record edited, record archived, record restored, possible duplicate identified, person linked, person unlinked, and correction recorded.

**System events:** backup created, backup downloaded, restore performed, and relevant configuration changes.

### 7.3 Audit-entry fields

Each audit entry should contain:

| Field | Requirement |
|---|---|
| Timestamp | Date and time in the configured parish timezone. |
| User ID and name | The account that performed the action, or system/anonymous where appropriate. |
| Role | Master Account or Secretary when an authenticated user performed the action. |
| Action | A stable machine-readable action name. |
| Module or target table | The affected system area or data entity. |
| Target ID | The affected request, application, record, account, or schedule ID where applicable. |
| Description | A human-readable summary of what occurred. |
| IP address | Captured for security-sensitive events where legally and operationally appropriate. |
| User agent | Captured for security investigation where appropriate. |
| Reason | Required for sensitive corrections, rejection, cancellation, deactivation, restoration, and role transfer. |

The log shall never store passwords, password-reset tokens, Gmail passwords, identity-document images, or complete document contents.

### 7.4 Audit-log access

The Parish Administrator shall have full visibility of all logs, including security events, Master Account actions, account actions, record changes, certificate lifecycle events, and system operations. The Parish Administrator may filter by date range, user, role, action, module, target record, or security category and may export a report.

The Secretary may view operational history needed to process parish work. Security events specifically relating to Master Accounts, role transfers, and protected configuration changes may be restricted to the Parish Administrator.

### 7.5 Audit-log integrity

The normal application interface shall provide no edit-log, delete-log, clear-log, or hide-log operation. If retention or archival is required in the future, it shall preserve the original entries and create a new audit entry describing the archival action. Any database-level maintenance procedure must be restricted, documented, and itself logged where technically possible.

---

## 8. Security Requirements

The system shall:

- Use password hashing and prepared SQL statements.
- Enforce role checks before rendering protected pages or processing protected POST requests.
- Use CSRF protection on state-changing forms.
- Escape user-provided output.
- Rate-limit login and public tracking attempts.
- Use session timeout and secure cookie settings in production.
- Require HTTPS in production.
- Keep `.git`, backups, development scripts, and configuration secrets outside public access.
- Keep Gmail SMTP credentials outside the repository and outside audit descriptions.
- Avoid collecting sensitive identity documents online.
- Preserve audit history for sensitive actions.
- Use archive/deactivation behavior rather than immediate destructive deletion.

---

## 9. Database Requirements

The database shall support, at minimum:

- `users` with individual account identity, role, password hash, active status, and timestamps.
- `persons` or an equivalent shared identity index.
- Sacramental record tables for Baptism, Confirmation, First Communion, Marriage, and the approved Death/Burial status record.
- `certificate_requests` and sacrament-specific extension tables.
- `sacrament_applications` and approved extension tables for Baptism and Marriage.
- Requirements-source, requirements-status, review-date, and review-note data for requests/applications.
- Schedules and announcements.
- Notifications.
- Append-only `audit_logs`.
- Migration/version tracking.

There shall be no payment, fee, amount, currency, receipt, or online-payment columns or tables. Existing contact-number columns may be retained temporarily for historical database compatibility, but new application code shall use email only and shall not require or write contact numbers. Removal of legacy columns shall occur through a controlled migration after historical-data review.

The database shall enforce the rule that at least one active Parish Administrator remains available. Account-management operations must not leave the system without a recoverable Master Account.

---

## 10. Functional Requirements Summary

The completed system must:

1. Run on PHP and MySQL/MariaDB through XAMPP.
2. Provide public informational pages without parishioner accounts.
3. Provide requirements and official Gmail instructions.
4. Allow the secretary to encode all requests and applications created onsite or after Gmail requirements review.
5. Use email as the official contact method.
6. Exclude payment and financial data completely.
7. Support searchable sacramental records and physical-register references.
8. Support certificate processing, printing, finalization, pickup readiness, and onsite release.
9. Provide optional tracking using a secretary-issued reference number and recorded email.
10. Provide individual Parish Administrator and Secretary accounts.
11. Prevent removal or deactivation of the last active Parish Administrator.
12. Allow the Parish Administrator to create, reset, activate, deactivate, and replace Secretary accounts.
13. Provide complete Parish Administrator visibility into append-only audit logs.
14. Prevent normal users from editing or deleting audit history.
15. Preserve security, privacy, backup, migration, and recovery controls.

---

## 11. Acceptance Scenarios

The system shall be tested with at least these scenarios:

1. A secretary creates an onsite baptismal certificate request using email only.
2. A secretary records requirements received through Gmail without creating a public account.
3. A certificate moves from received through processing, printing, finalizing, ready for pickup, and released.
4. A parishioner tracks a secretary-created request using the issued reference number and email.
5. A secretary leaves, and the Parish Administrator creates a replacement secretary account without deleting historical actions.
6. The Parish Administrator resets a secretary password and the action appears in the audit log.
7. The system refuses to delete or deactivate the last active Parish Administrator.
8. The Parish Administrator views and exports security and workflow logs.
9. An attempted audit-log edit or deletion is unavailable through the normal interface.
10. A request or record correction retains the previous action history and records the reason.
11. A public visitor cannot create an online request or application through the public website.
12. No payment or contact-number field is required anywhere in the approved workflow.

---

## References

[1]: ../PROJECT_CONTEXT.md "SRS-SPPO project context and implementation decisions"
[2]: ../README.md "SRS-SPPO project setup and deployment guide"
[3]: https://privacy.gov.ph/data-privacy-act/ "Philippine Data Privacy Act resources"

---

**Document status:** Updated to reflect the Parish Administrator/Master Account role, secretary account continuity, append-only audit logging, onsite processing, Gmail-based requirements handling, email-only contact, and the explicit exclusion of payment functionality.

**Author:** Manus AI
**Last updated:** September 2026

---

## Legacy implementation checklist

The current implementation should be reviewed against this specification in the following order:

1. Confirm the database schema and migrations support the Parish Administrator role and active-account safeguards.
2. Confirm the account-management interface supports secretary creation, password reset, activation, deactivation, and controlled Master Account transfer.
3. Convert public request/application routes to information-only pages or disable them.
4. Remove contact-number requirements from admin encoding and all approved workflows.
5. Add requirements source and requirements status fields and controls.
6. Align certificate statuses and dashboard labels with the onsite workflow.
7. Verify that all security and workflow events write append-only audit entries.
8. Verify the Parish Administrator audit-log filters and export function.
9. Remove temporary files and resolve duplicate migration numbering before production deployment.
10. Execute the acceptance scenarios above using anonymized test records.

This checklist is a release gate. The system should not be treated as production-ready until the requirements and acceptance scenarios have been verified.


## Phase 2 — Notifications and Email Alerts

The system provides two complementary alert channels. The secretary interface stores actionable in-app notifications for new requests, application status changes, certificate processing events, conflicts, and pickup activity. These notifications are visible through the administrator notification bell and the full Notifications page, where staff can mark one or all items as read. Notification event types are explicitly enumerated in the database so status alerts cannot be silently discarded by invalid values.

The system may send email alerts to the email address recorded during an onsite request or application. Email is not used to create public accounts, accept payment, deliver certificates, or replace onsite processing. Receipt emails provide the reference number and tracking-page link; status emails communicate the current workflow stage and remind the parishioner that certificates are released in person. Email is disabled by default until the parish configures a verified Gmail relay or other mail transport.

Every email attempt is recorded in `email_delivery_log` with the recipient, subject, event type, related transaction, reference number, delivery status, error message, and timestamp. Delivery statuses are `sent`, `failed`, `disabled`, or `invalid`. The secretary can review the last 100 attempts through Admin > Email Log and request a receipt resend for a related transaction. A failed or disabled email never prevents the secretary from saving a request, changing a status, printing a certificate, or releasing a certificate.


## Phase 3 — Account Continuity and Audit Accountability

The system supports two staff roles: Secretary and Parish Administrator. Parish Administrators inherit the operational workspace and are additionally responsible for account continuity, administrator safeguards, and protected system oversight. The account-management interface prevents the last active Parish Administrator from being deactivated, demoted, or deleted. Non-administrator secretaries cannot change Parish Administrator accounts. During first-time setup, when no Parish Administrator exists, the currently signed-in staff account may promote itself through the account-management form; subsequent administrator assignment is restricted to an existing Parish Administrator.

Audit entries remain append-only through the application. New entries record the authenticated role snapshot, client IP address, user-agent summary, and optional reason in addition to the action, target, description, and timestamp. The Audit Log page supports date and text search together with role, user, action, and module filters. The filtered audit history can be exported as CSV; exporting itself creates an audit entry. No edit, delete, clear, or hide operation is provided.


## Phase 4 — Public Workflow Alignment and Processing Controls

The public website now presents requests and sacrament applications as onsite parish-office services. Legacy direct submission routes redirect to the requirements and office-process information page, while public tracking remains available for secretary-issued references. The public website does not create accounts, accept payments, collect identity-document uploads, or submit online transactions.

Secretary-side requirements review now records the source of documentary requirements as not yet received, physical submission, official parish Gmail, or both physical and official Gmail. The reviewer can also preserve a missing-requirements or review note with the reviewing account and timestamp. The database includes non-destructive archive metadata for sacramental records and lifecycle fields for certificate finalization and cancellation.

Certificate generation now supports an explicit finalization confirmation after physical printing and signature/seal review. Cancellation requires a reason, changes the request to a preserved cancelled state, records the responsible staff member and timestamp, and writes an append-only audit event. Existing regeneration and physical-printing history remain available.
