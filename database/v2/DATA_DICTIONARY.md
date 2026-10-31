# SRS-SPPO Data Dictionary (schema v2, 12 tables)

Source of truth: `schema.sql`. If this file and `schema.sql` disagree, `schema.sql` wins.
Engine: MySQL / MariaDB, InnoDB, utf8mb4. All `id` columns are `INT UNSIGNED AUTO_INCREMENT`.

## 1. Table overview

| # | Table | Purpose | Replaces (old schema) |
|---|---|---|---|
| 1 | `users` | The single secretary/administrator account | `users`, `password_reset_tokens` |
| 2 | `persons` | Master identity, stored once per parishioner | `persons` |
| 3 | `sacrament_records` | Every sacrament event (baptism, first communion, confirmation, marriage) | 4 register tables |
| 4 | `sacrament_participants` | Godparents, sponsors, witnesses | `baptismal_sponsors`, `marriage_witnesses`, `marriage_principal_sponsors`, `baptismal_application_godparents` |
| 5 | `sacrament_applications` | Applications to receive a sacrament (unverified intake) | `sacrament_applications` + 4 application tables, `workflow_requirements` |
| 6 | `certificate_requests` | Certificate requests, from request to pickup | `certificate_requests` + 4 extension tables, `certificate_generation_log`, `submission_rate_limits` |
| 7 | `schedule_events` | Parish calendar | `schedule_events` |
| 9 | `announcements` | Public notices | `announcements` |
| 10 | `settings` | Key/value settings | `site_settings`, `sacrament_eligibility_settings` |
| 11 | `notifications` | In-app notices and email delivery log | `notifications`, `email_delivery_log` |
| 12 | `audit_logs` | Who did what | `audit_logs` |

Removed with no replacement: `srs_schema_migrations` (no migrations exist anymore), tracking-number lookup.

## 2. Relationships

```
users ──(encoded_by, verified_by, ...)──┐
                                        ▼
persons ◄── person_id ─────── sacrament_records ◄── record_id ── sacrament_participants
   ▲  ▲  ◄── spouse_person_id ──┘ (marriage only)
   │  └── father_person_id / mother_person_id (self link, optional)
   ├────── person_id ── certificate_requests ── record_id ──► sacrament_records
   ├────── person_id / spouse_person_id ── sacrament_applications ── record_id ──► sacrament_records
schedule_events ── application_id ──► sacrament_applications
```

## 3. Tables

### 3.1 users
| Column | Type | Null | Notes |
|---|---|---|---|
| full_name | VARCHAR(150) | no | Current account holder |
| username | VARCHAR(50) | no | Unique |
| email | VARCHAR(150) | no | Unique. Recovery address |
| password_hash | VARCHAR(255) | no | |
| role | ENUM('secretary') | no | Only one role exists |
| is_active | TINYINT(1) | no | Default 1 |
| auth_version | INT | no | Increase to invalidate all sessions |
| reset_token_hash, reset_expires_at | VARCHAR(255), DATETIME | yes | Forgot-password / handover link, stored hashed, single use |
| created_at, updated_at | DATETIME | no | |

### 3.2 persons
Rule: `last_name` is always the surname at birth. Unknown details are NULL (this replaces the old "not recorded" flags).

| Column | Type | Null | Notes |
|---|---|---|---|
| first_name, last_name | VARCHAR | no | |
| middle_name, suffix | VARCHAR | yes | |
| sex | ENUM('male','female') | yes | |
| birth_date, birth_place | DATE, VARCHAR(150) | yes | |
| father_name, mother_maiden_name | VARCHAR(150) | yes | Parents as text |
| father_person_id, mother_person_id | INT FK persons | yes | Optional link if the parent also has a profile |
| address, contact_number, email | VARCHAR | yes | |
| baptismal_status | ENUM('baptized_catholic','baptized_non_catholic','unbaptized') | yes | NULL = unknown |
| is_deceased, death_date | TINYINT(1), DATE | no / yes | Status flag only |
| created_at, updated_at | DATETIME | no | |

Indexes: `(last_name, first_name)`, `(birth_date)`.

### 3.3 sacrament_records
One row per sacrament event. The physical logbook stays canonical, so book/page/line are kept on every row.

| Column | Type | Null | Notes |
|---|---|---|---|
| sacrament_type | ENUM('baptism','first_communion','confirmation','marriage') | no | |
| person_id | FK persons | no | Recipient. Marriage: the groom |
| spouse_person_id | FK persons | yes | Marriage only (enforced by CHECK) |
| source | ENUM('this_parish','other_parish') | no | `other_parish` = entered from an origin certificate; shown on profile, no certificate printed here |
| event_date | DATE | no | |
| place, minister_name | VARCHAR(150) | yes | |
| entry_no, book_no, page_no, line_no | VARCHAR(20) | yes | Logbook coordinates |
| parents_union_type | ENUM('legitimate','civil','natural') | yes | Baptism only. Staff-facing "parents' marital status" |
| legitimacy | ENUM('legitimate','illegitimate') | yes | Baptism only. **INTERNAL. Never display, email or print.** New entries store NULL; it is not derived from `parents_union_type`. Kept only for legacy logbook notation |
| details | JSON | yes | See section 4.1 |
| remarks | TEXT | yes | |
| book_verified_by, book_verified_at | FK users, DATETIME | yes | Secretary confirmed the entry against the physical register |
| encoded_by | FK users | yes | |
| created_at, updated_at | DATETIME | no | |

Profile query for one person: `WHERE person_id = ? OR spouse_person_id = ?`.
Prerequisites are checked by looking for rows: First Communion needs a baptism row (or `persons.baptismal_status = 'baptized_catholic'`), Confirmation needs baptism and first communion, Marriage needs baptism for both parties. Prerequisite problems are flagged to the secretary, never auto-blocked.

### 3.4 sacrament_participants
| Column | Type | Null | Notes |
|---|---|---|---|
| record_id | FK sacrament_records | no | Deleted with the record |
| role | ENUM('godfather','godmother','sponsor','principal_sponsor','witness') | no | Confirmation sponsor uses `sponsor` |
| full_name | VARCHAR(150) | no | |
| residence | VARCHAR(150) | yes | |
| sort_order | TINYINT | no | Display order |

### 3.5 sacrament_applications
Unverified intake. Never writes to `persons` or `sacrament_records` by itself.

| Column | Type | Null | Notes |
|---|---|---|---|
| request_no | VARCHAR(20) | no | Unique. Internal reference, not a public feature |
| channel | ENUM('online','onsite') | no | |
| sacrament_type | ENUM (same four) | no | |
| person_id, spouse_person_id | FK persons | yes | Set when the secretary links profiles. Spouse is marriage only (checked in the app) |
| applicant_name, applicant_email, applicant_contact | VARCHAR | no, no, yes | |
| preferred_date, preferred_time | DATE, TIME | yes | |
| status | ENUM('new','reviewing','requirements_pending','approved','scheduled','completed','rejected') | no | See section 5 |
| details | JSON | yes | Form answers, see 4.2 |
| requirements | JSON | yes | Checklist ticked in person, see 4.3 |
| record_id | FK sacrament_records | yes | Record created after completion |
| notes, rejection_reason | TEXT | yes | |
| paid_at, paid_by | DATETIME, FK users | yes | Manual stamp, no amounts |
| processed_by | FK users | yes | |
| privacy_consent_at | DATETIME | yes | RA 10173 consent time |
| anonymized_at | DATETIME | yes | Set by the retention job |
| created_at, updated_at | DATETIME | no | |

### 3.6 certificate_requests
| Column | Type | Null | Notes |
|---|---|---|---|
| request_no | VARCHAR(20) | no | Unique. Internal reference only |
| channel | ENUM('online','onsite') | no | |
| sacrament_type | ENUM (same four) | no | |
| subject_name | VARCHAR(150) | no | What the requester typed. Marriage: groom |
| subject_details | JSON | yes | See 4.4 |
| requester_name | VARCHAR(150) | no | |
| requester_relationship | ENUM('self','parent','guardian','spouse','sibling','child','other') | no | Stage 1 of identity verification |
| requester_relationship_other | VARCHAR(100) | yes | |
| requester_email, requester_contact_number | VARCHAR | no, yes | |
| purpose, purpose_other | VARCHAR | yes | |
| privacy_consent_at | DATETIME | yes | |
| person_id | FK persons | yes | Set when the secretary matches |
| record_id | FK sacrament_records | yes | Record the certificate is printed from |
| matched_by, matched_at | FK users, DATETIME | yes | |
| status | ENUM('new','matched','for_signature','ready','released','rejected') | no | See section 5 |
| certificate_no | VARCHAR(30) | yes | Set at print |
| printed_by, printed_at | FK users, DATETIME | yes | |
| signed_at | DATETIME | yes | Priest signed on paper; triggers the "ready" email |
| claimant_type | ENUM('self','representative') | yes | Stage 2 at the counter |
| representative_name, representative_relationship | VARCHAR | yes | Only when claimant is a representative |
| id_verified | TINYINT(1) | no | "I verified valid ID at counter". Required before release |
| claimant_notes | TEXT | yes | |
| released_by, released_at | FK users, DATETIME | yes | |
| paid_at, paid_by | DATETIME, FK users | yes | Fee collected in person |
| notes, rejection_reason | TEXT | yes | |
| anonymized_at | DATETIME | yes | |
| created_at, updated_at | DATETIME | no | |

Reprints are recorded in `audit_logs`, not in a separate table.

### 3.8 schedule_events
`event_type` ENUM('mass','wedding','baptism','other'), `title`, `public_details` (shown publicly), `event_date`, `start_time`, `end_time`, `application_id` (FK, nullable), `notes` (internal only), `created_by`, `created_at`.

### 3.9 announcements
`title`, `content`, `event_date` (nullable), `is_published`, `posted_by`, `created_at`, `updated_at`.

### 3.10 settings
`setting_key` (PK, VARCHAR 64), `setting_value` (TEXT), `updated_by`, `updated_at`.
Planned keys (to be finalized with the settings page): `parish_name`, `first_communion_min_age`, `first_communion_max_age`, `confirmation_min_age`, `confirmation_max_age`, `priest_approval_email`, `retention_months`.

### 3.11 notifications
| Column | Type | Notes |
|---|---|---|
| channel | ENUM('inapp','email') | `inapp` uses title/message/link/is_read. `email` uses recipient/delivery_status/error_message |
| type | VARCHAR(60) | e.g. `request_new`, `request_ready`, `event_reminder` |
| title | VARCHAR(150) | Email subject for `email` rows |
| message, link | VARCHAR | nullable |
| recipient | VARCHAR(150) | Email rows |
| delivery_status | ENUM('sent','failed','disabled','invalid') | Email rows |
| error_message | VARCHAR(500) | Email rows |
| entity_type, entity_id | VARCHAR(40), INT | What the notice is about |
| is_read, read_at | TINYINT(1), DATETIME | In-app rows |
| created_at | DATETIME | |

### 3.12 audit_logs
`user_id` (FK), `actor_name` (account holder's name at that time), `action`, `target_table`, `target_id`, `description`, `reason`, `ip_address`, `user_agent`, `created_at`.

## 4. JSON column keys

JSON is used only for fields that are never searched or joined. Keys below are the planned set and will be finalized when each form is rebuilt.

### 4.1 sacrament_records.details
- **baptism:** `father_birthplace`, `mother_birthplace`, `parents_origin`, `parents_residence`, `proxy_name`
- **first_communion:** `domicile`
- **confirmation:** none
- **marriage:** `groom` and `bride`, each with `civil_status`, `age`, `origin`, `residence`; plus `license_ref`, `civil_marriage_date`, `civil_marriage_place`, `banns_dates` (list), `pre_cana_date`, `canonical_interview_date`

### 4.2 sacrament_applications.details
The applicant's form answers for that sacrament, for example baptism: child name, birth date, birthplace, father, mother's maiden name, parents' union type, guardian name, delegated minister, godparent list. Marriage: `groom` and `bride` objects (name, birth data, address, marital status, religion, occupation, baptism status, confirmed, previous marriage, CENOMAR status) plus venue, license reference, banns and seminar dates.
`baptism_status` for First Communion, Confirmation and Marriage candidates is one of `this_parish`, `elsewhere`, `not_baptized`.

### 4.3 sacrament_applications.requirements
Object keyed by requirement, each `{ "status": "not_received|received|verified|not_applicable", "note": "" }`. Examples: `psa_birth_certificate`, `origin_baptismal_certificate`, `cenomar`, `transfer_letter`, `pre_cana_seminar`. Documents are shown in person only and never uploaded.

### 4.4 certificate_requests.subject_details
`birth_date`, `father_name`, `mother_maiden_name` for baptism, first communion and confirmation. Marriage: `spouse_name`, `marriage_date`.

## 5. Status flows

**Certificate:** `new` (payment recorded first) → `matched` (secretary linked a person and record, book entry verified; refused until `paid_at` is set) → `for_signature` (printed) → `ready` (priest signed, email sent) → `released` (counter check done). `rejected` is possible before release.

**Application:** `new` → `reviewing` → `requirements_pending` (back and forth with `reviewing`) → `approved` → `scheduled` → `completed`. `rejected` is possible before completion.

## 6. Business rules
1. Identity fields exist only in `persons`. Register forms show them read-only for existing people.
2. Baptism creates the profile. Someone not yet baptized exists only as an application. Exception: a non-Catholic or unbaptized spouse in a marriage may have a profile with the matching `baptismal_status`.
3. Public submissions never write to `persons` or `sacrament_records`.
4. `legitimacy` is never exposed publicly, in email, or on certificates.
5. A certificate can be printed only for a `this_parish` record whose `book_verified_at` is set.
6. Release requires `claimant_type` and `id_verified = 1`; a representative also needs `representative_name`.
7. Prerequisites and marriage requirements (CENOMAR, origin baptismal certificate, transfer letter, one party a registered parishioner) are flagged for the secretary, not auto-blocked.
8. Identity verification is in two stages: Stage 1 the relationship choice plus the upfront pickup notice online; Stage 2 the counter ID check at release.
9. No monetary amounts are stored. Payment is a manual `paid_at` stamp, recorded at the counter before processing starts (the parish's real rule).
10. Retention: the backend job sets `anonymized_at` on old requests; sacrament records are never purged.
