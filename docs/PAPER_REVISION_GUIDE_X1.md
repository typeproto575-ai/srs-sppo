# Revision Guide for `x1_revised.docx`

This guide identifies specific paper passages that should be corrected for consistency with the current system and the previously reviewed chapter files. It is an editing guide, not an instruction to change the paper automatically. Apply only the changes that match the version being submitted, then update the table of contents and page numbers in Word.

## Source and scope

The review compared `x1_revised.docx` with the available Chapter I–II, Chapter III, Chapter IV, and Appendix E source documents, the prior paper revision guide, and the current local system. The clearest remaining gaps are in Appendix E, the persons data dictionary, and descriptions of correction requests and Archive/Restore.

The hosted and local builds may differ. Before describing a control or menu item as available, confirm it in the exact build being documented.

## Appendix E — User Manual

### 1. Update the dashboard Requests bullet

**Find** the bullet under “Use the dashboard” that currently reads:

> Requests: Online Submissions, Certificate Requests, Sacrament Pre-Applications, and walk-in encoding.

**Replace it with:**

> Requests: Online Submissions, Certificate Requests, and Sacrament Pre-Applications. For a known parishioner, start a walk-in pre-application from the person's profile using “New walk-in pre-application.” The application-queue button opens the Baptism pre-application directly.

Keep the existing bullet formatting.

### 2. Remove unsupported correction-request navigation and workflow

In the staff navigation list, **remove “Correction Requests”** from the Certificates bullet. Keep only certificate tools that are visible in the exact system build being documented. Do not describe a separate correction-request queue unless it has been confirmed in that build.

**Find** the section headed “Processing correction requests,” including the instructions to open “Certificates > Correction Requests” and use “Mark as Processing,” “Admin Review Note,” or “Mark as Completed.”

**Replace the heading and section text with:**

> **Correcting a parish record**
>
> The system does not provide a public correction-request queue. When a parishioner reports an error, follow Parish Office procedures and verify the information against the official physical parish register or other appropriate source. Authorized staff make a confirmed correction through the relevant person or record editing page. Enter the correction reason requested by the form and review the saved change in the record or person history. Do not change a parish record based only on an unverified report.

This describes the staff-side correction and audit-history workflow; it does not imply that a public correction form or queue exists.

### 3. Replace the walk-in instructions

**Find** the paragraph under “Walk-in requests and pre-applications” that begins:

> Use Walk-in Request or New walk-in pre-application when the parishioner is present...

**Replace it with:**

> Use Walk-in Request for an at-office certificate request. For an onsite Baptism pre-application, the application-queue button opens the Baptism form directly. To start a walk-in pre-application for a known parishioner for another sacrament, open Records > Persons, open that person's profile, and choose “New walk-in pre-application.”
>
> The selected person's available profile details are prefilled. Confirm them with the parishioner and complete the remaining required and sacrament-specific information before submitting. A sacrament shown on the profile is one linked in this system; the profile identifies whether its source is this parish or another parish. If no record is linked, do not assume the person never received the sacrament elsewhere. Ask the parishioner and verify with the originating parish when needed before starting a new application.
>
> For Marriage, the profile's recorded sex selects the person's groom or bride role when it is available. If sex is not recorded, choose the correct role with the parishioner. Ask about any prior marriage. Refer a prior or reported prior marriage to the priest before marriage preparation or scheduling proceeds.

Keep the paragraphs together under the existing walk-in heading.

### 4. Remove Archive/Restore instructions unless confirmed in the release build

**Find** the bullet under “Sacramental records” that says:

> Use Archive/Restore rather than attempting to permanently remove a historical entry. Confirm the record identity before archiving or restoring.

The current local v2 records interface does not expose Archive/Restore controls. **Remove this bullet** unless the exact release build has those controls. Also remove “Archive / Restore” from the System navigation list if it is not present in the release build.

If the dashboard sentence says it summarizes “sacramental archive totals,” replace that phrase with **“sacramental record totals”** or the exact label shown by the release build.

### 5. Correct the data-cleanup description

**Find** the cleanup paragraph that lists correction requests among the records anonymized by cleanup, including:

> ...and correction requests (completed or rejected)...

**Remove that clause.** Retain the descriptions of certificate requests, sacrament pre-applications, audit logs, email-delivery logs, notifications, rate limits, and password-reset tokens only if each matches the current cleanup implementation. The current v2 workflow does not have a correction-request queue.

## Chapter III — Results and Discussion

### 6. Add the missing `observanda` field to the persons data dictionary

Find **Table 12, “Data Dictionary for persons.”** The current table groups `address`, `contact_number`, and `email` together and then lists `baptismal_status`.

**Insert a new row immediately after the row containing `email` and before `baptismal_status`:**

| Field Name | Data Type | Description |
|---|---|---|
| observanda | TEXT | Optional person-level note. This is separate from remarks on an individual sacramental record. |

Make sure the field name is spelled exactly `observanda` and that the row is part of the persons table, not a sacramental-record table.

### 7. Adjust the Agile development feature list

In the paragraph beginning “Coding followed the Agile methodology,” **replace** the phrase:

> correction requests

**With:**

> staff-side record correction and audit-history functions

This avoids implying that the system includes a separate correction-request intake queue.

## Chapter IV — Summary, Conclusions, and Recommendations

### 8. Replace the system summary paragraph

Find the paragraph beginning:

> The system was developed following the Agile stages. It supports certificate requests...

**Replace the entire paragraph with:**

> The system was developed following the Agile stages. It supports certificate requests and sacrament applications for four sacraments; reference numbers with staff-side request status management (without public online lookup); a digital register index with book, page, and line references; certificate generation with print and release logging; a schedule with conflict flags for staff review; announcements; reports; secretary-account access controls; and audit logging. Corrections to parish records follow office procedures and are not submitted through a public correction form. Automated tests with PHPUnit and static analysis with PHPStan were used during testing. All sixteen automated test methods (eleven integration tests and five function tests) passed; PHPStan reported no errors at the configured analysis level; and the continuous integration workflow ran successfully on PHP 8.2 and MariaDB 10.4.

This retains the historical test results from the study version while clarifying that request status updates are staff-side and that there is no public correction form.

## Final consistency checks

- Search the entire document for **“Correction Requests,” “correction requests,” “Mark as Processing,” “Admin Review Note,”** and **“Mark as Completed.”** Remove or revise passages that imply a correction-request queue.
- Search for **“Archive/Restore,” “Archive / Restore,”** and **“archive totals.”** Keep them only if confirmed in the exact release build.
- Confirm Table 12 includes `observanda` under persons.
- Confirm the walk-in paragraph matches the staff workflow and does not imply that a missing linked record proves the person never received a sacrament elsewhere.
- Refresh the table of contents, figure/table references, and page numbers after editing.
- Do not change historical test counts unless reporting a newly evaluated version with its exact test run and date.