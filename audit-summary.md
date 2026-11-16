# SRS-SPPO QA audit summary

## Result and limits

This is a **partial audit**, not a sign-off. The browser was used with demo/test inputs and no final application or certificate request was submitted. No admin record, request, account, schedule, notification, backup, or email mutation was performed. The API no-op mark-read test used notification ID 0 and left the unread count unchanged. No credentials or personal names/emails are included here.

All 44 requested public/admin pages and certificate components, plus the five discovered `admin/api/*` endpoints, have a page-level row in `audit-table.csv`. That does **not** mean all their controls received the requested four passes. Page-wide rows remain `UNCLEAR` where controls were not individually inventoried, traced through every reader, and tested with valid, empty, invalid, and wrong-order actions. Granular rows record only the specific controls and behaviors tested in this continuation.

Browser screenshots of the reproduced queue fatal, single-slide carousel, and marriage review are attached inline in the audit conversation and named in the CSV. They were not exported into the workspace because the requested deliverables are the only files created. The PHP error log was checked; the applications-queue fatal is present in it. Browser console output repeatedly reported a CSP-blocked `data:` icon font.

The queue was retested after migrations 048 and 049 were reported present; it still fails at runtime on `sa.assigned_to`. Queue checklist/status/prefill controls therefore remain explicitly `UNCLEAR`, not passed. The Marriage wizard was retested with Married, Single + previous civil marriage, Single + previous church marriage, and a past wedding date; each reached Review, without a final submission.

## Counts

Counts below are row counts from `audit-table.csv`. Page/endpoint coverage rows intentionally remain `UNCLEAR`, not `OK`.

### By class

| Class | Count |
|---|---:|
| A | 0 |
| B | 1 |
| C | 0 |
| D | 6 |
| E | 1 |
| F | 1 |
| G | 0 |
| H | 0 |
| I | 2 |
| J | 0 |
| K | 1 |
| OK | 23 |
| UNCLEAR | 55 |
| **Total** | **90** |

### By severity

| Severity | Count |
|---|---:|
| BLOCKER | 1 |
| SHOULD FIX | 9 |
| MINOR | 3 |
| UNCLEAR | 54 |
| N/A (tested OK rows) | 23 |
| **Total** | **90** |

## Top 10 blockers

Only **one BLOCKER was confirmed**. I cannot responsibly promote untested or lower-severity findings to fill ten slots; the incomplete page/control coverage means more blockers may remain undiscovered.

1. **Admin cannot open the sacrament applications queue (Class I).** `admin/requests/applications-queue.php` fatals at line 455 because the live database reports unknown column `sa.assigned_to`. This prevents the secretary from reviewing pre-applications; the failure was reproduced in the browser and appears in the PHP error log. Evidence name: `applications-queue-fatal-inline`.

### Other priority findings

- **SHOULD FIX — Past wedding date passes to review (Class D).** The marriage wizard accepted `2020-01-30` as the target wedding date and reached Review & Submit. No final submission was made. Evidence name: `marriage-past-date-review-inline`.
- **SHOULD FIX — Prior-marriage answer passes through and is absent from applicant review (Class D).** “Yes — Church” reached Review & Submit. The answer is stored under the application `details` JSON in source, but the applicant review table omits it. The admin queue source decodes submitted details, but the live queue is blocked by the fatal above. This is a review/eligibility visibility issue, not proof that the parish would accept the marriage.
- **SHOULD FIX — Groom marked Married can still reach review (Class D).** The synthetic Marriage wizard advanced to Review & Submit with the groom's marital status set to Married. No submission was made. The Single + prior civil and Single + prior church scenarios also advanced and omitted the prior-marriage answer from applicant review; the parish eligibility policy was not inferred.
- **SHOULD FIX — Email delivery unavailable in this tested environment (Class E).** The Email Log showed 18 delivery attempts and all 18 were Disabled with `Email is disabled.` errors. No email was sent or retried. This is evidence of the current environment's disabled delivery path, not proof that every deployment lacks SMTP configuration; email-dependent flows remain unverified.
- **SHOULD FIX — First Communion Baptism finder selection ID is not saved or read (Class B).** The browser showed selected Baptism match #87, but the First Communion handler does not read the posted `baptismal_record_id`, the insert has no such value, and the edit query returns `NULL AS baptismal_record_id`. The selected ID therefore has no downstream reader; `person_id` remains the sacramental cross-link. No record was saved during the test.
- **SHOULD FIX — Death/Funeral record type is absent (Class K).** The browser-visible admin record-type dropdown offered only Baptismal, Confirmation, First Communion, and Marriage. No death/funeral type appeared in the requested certificate components. This is listed because the audit request specifically identifies death/funeral as a panel-requested feature.
- **MINOR — Crafted invalid report query passes through (Class D).** Normal year input validation blocked 0 and 2101, but `month=13&year=not-a-year` rendered a November 0 report when entered directly in the URL.
- **MINOR — Single-item announcement carousel shows active arrows (Class F).** With one slide, Previous and Next remained visible and clickable; clicking Next did not change content. The JS source intends to hide the controls for a single slide. Evidence name: `announcements-single-slide-inline`.
- **MINOR — Browser console CSP error for the icon font (Class I).** A `data:` font is blocked by `font-src 'self' https://fonts.gstatic.com`; this may leave icon glyphs unavailable. Pages otherwise rendered during the audit.
- **SHOULD FIX — Privacy dates remain literal placeholders.** `Effective: [DATE]` and `Last Updated: [DATE]` were visible in the privacy notice. The classification is `UNCLEAR` because this is an unfinished-content issue outside the A–K behavior classes.

## Walk-the-flow

### Baptism

`Services → Baptism pre-application (identity/details → parent/guardian and sacrament details → contact/consent → review)`. Empty/incomplete progression was blocked, future birth date was rejected, Back preserved entered data, and a selected master-person match populated biographical fields. The audit stopped before submission. A real parishioner can reach review; successful submission and secretary completion are **not verified**. A secretary cannot review applications through the applications queue while its fatal remains.

### First Communion

`Services → First Communion application → applicant/details steps → age notice → review`. A synthetic under-age applicant reached review; the screen explicitly describes age eligibility as advisory. In the admin record editor, selecting a Baptism match was tested, but the selected Baptism ID is not saved/read by the First Communion handler (Class B); no sacramental record was saved. Parishioner completion is **not verified**; secretary processing is blocked at the applications queue.

### Confirmation

`Services → Confirmation application → applicant/details steps → age notice → review`. A synthetic under-age applicant reached review and saw the same advisory-only behavior. No submission was made. Parishioner completion is **not verified**; secretary processing is blocked at the applications queue.

### Marriage

`Services → Wedding details → Groom → Bride → Church & civil status/sponsors → review`. The wizard reached review for all tested cases: Married; Single + previous civil marriage; Single + previous church marriage; and a past target date. The previous-marriage answer was absent from applicant review, and no eligibility stop appeared for Married. No submission was made. A parishioner can reach review but eligibility/data review is not reliable enough to call the flow complete. The secretary queue is blocked.

### Certificate request

`Public portal → Certificate request (requester/subject lookup → relationship/purpose/contact → review) → secretary certificate queue → request detail → print preview`. Empty progression was blocked; master-person lookup and Self requester autofill were observed; “Other” purpose required details. The final request was not submitted. The secretary could open an existing seeded request and its print preview; blank rejection was blocked by the required reason and Mark For Signature was disabled until print. Actual printing, signature, release, and completion were not tested. Email delivery was unavailable in the tested environment. Neither full user journey is verified.

### Secretary review

The existing certificate request queue, detail view, and print preview loaded read-only. The separate pre-application queue consistently crashed on the missing `sa.assigned_to` column even after migrations 048/049 were reported present, so checklist statuses, Save requirement button state, Decline/Pending behavior, and Prefill Register Form remain untestable and the secretary cannot complete that review flow in this environment. No approval/rejection/payment/signature/release action was performed.

### Tracker

`Track request → reference/email screen → access-code screen → code verification → status view`. A deliberately invalid reference reached the generic code step; an invalid six-digit code was rejected. A valid reference and code were not attempted because that could send mail to an existing record address. The Email Log independently showed 18/18 attempts disabled in this environment. End-to-end tracking and delivery are **not verified**.

## Pages/endpoints not fully tested, and why

The following all have explicit `UNCLEAR` coverage rows in `audit-table.csv`; none should be interpreted as passing:

- **Public:** `public/index.php` (except the single-slide carousel), `services.php`, `requirements.php`, `how-it-works.php`, `privacy.php`, `terms.php`; all unlisted controls on the four sacrament wizards; unsubmitted final paths for `certificate-request.php`; successful-code path for `track-request.php`.
- **Admin:** `login.php` edge cases, `forgot-password.php` successful email/reset, `reset-password.php` valid-token flow, remaining dashboard/notification actions, all application-queue controls (page crashes), certificate queue/detail mutations and actual printing/signature/release, record editor saves and record-type page-specific actions, standalone certificate components without print context, full report calculations/date boundaries, schedule and announcement valid mutations/conflict resolution, account changes, backup/restore, audit/email-log filter combinations, person-edit valid corrections, and full age-eligibility boundaries. Monthly/yearly exports, required-field guards, a subset of filters, and error gates were tested; those limited results are in granular CSV rows.
- **APIs:** Successful POST to `application-person-link.php` was avoided because it changes identity links. Search endpoints were tested with selected valid/empty/invalid inputs and notifications with GET, CSRF rejection, and a no-op ID; other edge cases and real state mutations remain untested.

The reset page only showed an invalid/expired-link state because no reset token was generated. Certificate components are included by the print flow and were not meaningfully testable as standalone pages. All test submissions and consequential admin mutations were withheld to keep the environment read-only and avoid creating or altering parish records.
