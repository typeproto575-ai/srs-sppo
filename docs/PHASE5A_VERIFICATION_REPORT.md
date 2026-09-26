# SRS-SPPO Phase 5A Verification Report

**Implementation:** Online intake and pre-application workflow

**Project:** Saint Patrick's Parish Office Schedule and Record System

**Prepared by:** Manus AI

## Executive result

Phase 5A has been implemented in the extracted Phase 4 project. The system now supports public online pre-applications while preserving the parish's non-negotiable controls: no parishioner accounts, no online payment, no identity-document uploads, no public record search, no automatic approval, and no digital certificate release.

> Public submission is an intake convenience, not an official parish record or an automatic transaction.

## Implemented capabilities

| Area | Result | Implementation |
|---|---:|---|
| Public service selection | Complete | Reworked `public/certificate-request.php` with four certificate routes and Baptism/Marriage application routes. |
| Public online certificate requests | Complete | Reactivated all four existing transaction-safe forms and marked rows with `submission_channel = online`. |
| Public sacrament applications | Complete | Reactivated Baptism and Marriage application forms and marked rows with `submission_channel = online`. |
| Confirmation and tracking | Complete | Retained shared confirmation component, tracking number generation, status lookup, generic failure messaging, and tracking-plus-email verification. |
| Email-only requirements workflow | Complete | Public forms state that requirements are provided through official parish Gmail after review or onsite; no upload path was added. |
| Admin online queue | Complete | Added `admin/requests/online-requests.php` and a navigation link. |
| Existing admin queues | Complete | Added online/onsite filters, channel columns, and extended status filters. |
| Secretary detail review | Complete | Added submission-channel provenance to detail pages and retained requirements checklist, internal notes, audit history, and secretary-only status controls. |
| Onsite compatibility | Complete | Walk-in certificate and application encoders explicitly mark new rows as `onsite`; existing rows remain compatible through the migration default. |
| Schema | Complete | Added migration `023_phase5a_online_intake.sql` and aligned `database/srs_sppo_schema.sql`. |
| Documentation | Complete | Added `docs/PHASE5A_ONLINE_INTAKE.md`. |

## Verification performed

All PHP files in the project passed `php -l` syntax checks after the implementation. The six public forms were checked for CSRF verification, public abuse-cap enforcement, successful-submission quota recording, audit logging, email notification hooks, and explicit online-channel inserts. The onsite encoders were checked for explicit onsite-channel inserts. Public navigation was checked for reachable links to the service selector, workflow explainer, requirements page, and tracking page.

The public tracking implementation continues to require both the tracking number and recorded email address. It returns a generic failure message for malformed, missing, or mismatched lookups, retains failed-attempt rate limiting, and exposes only a limited status timeline. Private record fields, internal notes, audit metadata, and staff names are not included in the public result.

## Database deployment requirement

Before using the online forms against a Phase 4 database, apply `migrations/023_phase5a_online_intake.sql` after the existing Phase 4 migrations. The migration adds `submission_channel` to both shared transaction tables, defaults existing and new onsite rows to `onsite`, adds indexes for channel/date filtering, and extends the status enums for requirements review, onsite verification, processing, and cancellation.

The sandbox did not contain the parish staging database or its connection credentials, so a live insert-and-track scenario could not be executed here. The deployment team should run the following acceptance scenarios on the isolated staging database before production use:

1. Submit one valid online Baptismal Certificate request and confirm the shared row, extension row, tracking reference, audit entry, notification, online channel, and rate-limit row.
2. Submit one valid online Baptism application and confirm the same channel, tracking, audit, and notification behavior.
3. Open the online-submissions queue and verify both records appear with the correct type and channel.
4. Verify that a wrong email cannot retrieve either record and that the public message does not reveal whether the tracking number exists.
5. Move a certificate through secretary review, requirements, onsite verification, approval, generation, pickup readiness, and release using the existing secretary controls.
6. Encode one walk-in request and one walk-in application and verify both are marked `onsite`.
7. Confirm that no public page exposes identity-document upload, payment, public record search, or certificate download actions.

## Known deployment notes

The official parish Gmail relay must be configured and tested separately before relying on outbound confirmation or status-update email. The public forms are deliberately safe if email delivery fails: a failed notification does not roll back a valid submission. The migration should be backed up and applied first in the existing isolated staging environment.
