# Phase 5A — Online Intake and Pre-Application

## Operational rule

The SRS accepts online pre-applications, not unrestricted public record transactions. A parishioner may submit details without an account, but the Secretary remains responsible for reviewing the submission, checking requirements, verifying identity onsite, approving the request, processing the certificate or sacrament application, and releasing the final result in person.

> An online submission is not an official sacramental record, does not guarantee approval, does not accept online payment, and does not release a certificate digitally.

## Public capabilities

The public service selector links to the four certificate request forms and all four sacrament application forms: Baptism, Confirmation, First Communion, and Marriage. Each form uses the existing CSRF-protected, prepared-statement, transaction-safe pattern and records `submission_channel = online`. The shared confirmation panel displays the tracking number and points the requester to public tracking by tracking number plus recorded email.

Requirements are not uploaded through the public site. The requester is instructed to show them in person at the Parish Office. Identity documents are not stored by the system; identity is verified in person when required.

## Secretary capabilities

The secretary has a dedicated Online Submissions queue, plus Online/Onsite filters and channel columns in the existing certificate and application queues. The queue links to the established type-specific detail pages, where requirements checklists, review notes, audit history, status controls, record matching, certificate generation, and application approval remain staff-only.

## Data changes

Migration `023_phase5a_online_intake.sql` adds `submission_channel` to `certificate_requests` and `sacrament_applications`, defaulting existing and new onsite rows to `onsite`. Public forms explicitly write `online`, and walk-in encoders explicitly write `onsite`. The migration also extends the status enums with requirements review, onsite verification, processing, and cancellation stages while preserving legacy values.

## Privacy and abuse controls retained

The implementation retains no public accounts, generic tracking lookup failures, tracking-number plus email verification, tracking rate limiting, public submission caps, CSRF protection, prepared SQL statements, escaped output, private staff notes, audit logging, and no public record search. The final certificate is still printed and released at the parish office.

## Deployment sequence

1. Back up the staging database.
2. Apply all existing Phase 4 migrations, then apply `migrations/023_phase5a_online_intake.sql`.
3. Deploy the updated application files.
4. Confirm that public form submissions create `online` channel rows and that walk-in encoding creates `onsite` channel rows.
5. Verify the official Gmail relay before relying on confirmation/status email notifications.
6. Perform the acceptance checks in the Phase 5A verification report before production use.
