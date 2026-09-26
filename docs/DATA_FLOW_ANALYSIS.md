# Connected Data Architecture Analysis — SRS-SPPO

This document records the current state of the data model and the gaps between modules. This is analysis only; no code, schema, or data was changed.

## Scope and guardrails

The project intentionally keeps public-facing users separate from internal identity records:

- Public parishioners do not get login accounts; they are tracked only by tracking number/email for requests and applications, per [PROJECT_CONTEXT.md](../PROJECT_CONTEXT.md) and [README.md](../README.md).
- Identity verification happens in person at pickup; no public ID upload is collected, per [PROJECT_CONTEXT.md](../PROJECT_CONTEXT.md).
- The internal person entity is a database-only identity index, not a public user account.

These scope decisions matter because any connected architecture must be internal-only, not public-facing.

---

## Part 1 — Current data flow

### 1. Sacrament record tables

The canonical schema defines a shared internal identity index in [database/srs_sppo_schema.sql](../database/srs_sppo_schema.sql#L30-L55): the persons table exists as a central identity bucket, and several sacrament tables have bridging columns such as person_id, husband_person_id, and wife_person_id.

#### A. Baptismal records

Creation path:
- Secretary encodes a new baptismal register row in [admin/records/baptismal.php](../admin/records/baptismal.php#L66-L165).
- Insert statement: [admin/records/baptismal.php](../admin/records/baptismal.php#L121-L165)
- Related data model: [database/srs_sppo_schema.sql](../database/srs_sppo_schema.sql#L56-L93)

Current structure:
- Core table: baptismal_records
- Key identity fields: child_full_name, birth_date, father_name, mother_name, mother_maiden_name, etc.
- Logbook fields: entry_no, book_no, page_no, line_no, date_encoded, encoded_by
- Link fields: certificate_request_id, person_id
- A separate table, baptismal_sponsors, stores godparents

Current linkage status:
- There is a nullable person_id bridge to persons.
- It is not populated automatically when a baptism is created.
- The Person Mapper is the manual tool used to assign a person record to the row: [admin/system/person-mapper.php](../admin/system/person-mapper.php#L22-L111)
- The row is therefore not fully standalone, but it is still effectively a duplicate identity dataset until the mapper is used.

#### B. Confirmation records

Creation path:
- [admin/records/confirmation.php](../admin/records/confirmation.php#L35-L85)
- Related schema: [database/srs_sppo_schema.sql](../database/srs_sppo_schema.sql#L95-L117)

Current structure:
- confirmation_records with confirmand_full_name, birthdate, father_name, mother_maiden_name, sponsor_name, etc.
- Link fields: baptismal_record_id, certificate_request_id, person_id

Current linkage status:
- There is a person_id bridge and a prerequisite link to baptismal_record_id.
- But the person_id is not assigned automatically at row creation.
- It is a manual mapping step via Person Mapper, not automatic linkage.

#### C. First Communion records

Creation path:
- [admin/records/first-communion.php](../admin/records/first-communion.php#L35-L85)
- Related schema: [database/srs_sppo_schema.sql](../database/srs_sppo_schema.sql#L119-L140)

Current structure:
- first_communion_records with communicant_full_name, domicile, father_name, mother_maiden_name, minister_name, baptismal_record_id, etc.
- Link fields: baptismal_record_id, certificate_request_id, person_id

Current linkage status:
- It also has a person_id bridge and a prerequisite link to baptismal_record_id.
- This is closer to a connected model than the certificate request layer, but it still requires manual mapping to persons.

#### D. Marriage records

Creation path:
- [admin/records/marriage.php](../admin/records/marriage.php#L49-L118)
- Related schema: [database/srs_sppo_schema.sql](../database/srs_sppo_schema.sql#L142-L173)

Current structure:
- marriage_records with husband_full_name, wife_maiden_name, husband_birthdate, wife_birthdate, officiating_minister, etc.
- Link fields: certificate_request_id, husband_person_id, wife_person_id
- Witnesses stored separately in marriage_witnesses

Current linkage status:
- Marriage is the clearest example of a sacrament record already trying to link to a central person entity, because it stores both husband_person_id and wife_person_id.
- However, these are still nullable bridge columns, not enforced by automatic population.
- They are assigned manually via the Person Mapper, not at insert time.

#### Summary of sacrament-table linkage

| Module | Main insert page | Primary identity data in table | person_id / spouse person bridge | Auto-linked at create time? |
| --- | --- | --- | --- | --- |
| Baptismal | [admin/records/baptismal.php](../admin/records/baptismal.php#L66-L165) | child_full_name, father_name, mother_name | person_id | No |
| Confirmation | [admin/records/confirmation.php](../admin/records/confirmation.php#L35-L85) | confirmand_full_name | person_id | No |
| First Communion | [admin/records/first-communion.php](../admin/records/first-communion.php#L35-L85) | communicant_full_name | person_id | No |
| Marriage | [admin/records/marriage.php](../admin/records/marriage.php#L49-L118) | husband_full_name, wife_maiden_name | husband_person_id + wife_person_id | No |

### 2. Certificate Requests table

Schema:
- Shared table: [database/srs_sppo_schema.sql](../database/srs_sppo_schema.sql#L195-L214)
- Extension tables: [database/srs_sppo_schema.sql](../database/srs_sppo_schema.sql#L215-L299)

Current structure of certificate_requests:
- tracking_number
- submission_channel
- sacrament_type
- requester_name
- requester_relationship
- requester_email
- requester_contact_number
- purpose / purpose_other
- notes
- status
- date_submitted, date_processed, processed_by

Important finding:
- The request table stores the requester as plain text (requester_name, requester_email, etc.)
- The subject of the certificate request is stored separately in the sacrament-specific extension table, again as free text (for example child_full_name, communicant_full_name, party_a_full_name)
- It does not directly store a foreign key to the actual parishioner or actual sacrament record row as the source of truth.

The extension tables do have matched_record_id, which is the closest thing to a real link:
- Example: [database/srs_sppo_schema.sql](../database/srs_sppo_schema.sql#L215-L299)
- Matching is handled in [admin/certificates/generate.php](../admin/certificates/generate.php#L43-L96) and displayed in [admin/requests/certificate-detail.php](../admin/requests/certificate-detail.php#L24-L41)

How review works today:
- The queue loads a request and shows the subject name from the extension table in [admin/requests/queue.php](../admin/requests/queue.php#L18-L140)
- The detail page displays the matched register record only if matched_record_id is already set; otherwise it says “No register record matched yet” in [admin/requests/certificate-detail.php](../admin/requests/certificate-detail.php#L146-L176)
- The actual request-to-record matching is a separate step inside the certificate generator, not an automatic result of the request insert: [admin/certificates/generate.php](../admin/certificates/generate.php#L43-L96)

So the current flow is:
- request submitted with free-text subject and requester names
- secretary reviews the request and maybe searches manually or through the generate page
- the system shows a matched record only after a separate match action is performed
- there is no direct FK from certificate_requests to a parishioner_id or to the actual sacrament record row as a mandatory part of the submission flow

This is a real disconnect between request data and canonical record data.

### 3. Person Mapper: what it actually does

The Person Mapper exists in [admin/system/person-mapper.php](../admin/system/person-mapper.php#L22-L111) and [admin/system/person-profile.php](../admin/system/person-profile.php#L28-L81).

Its role:
- It is not an automatic system step.
- It is a separate admin tool where the secretary picks a record row and manually assigns it to a persons row.
- It allows either:
  - assigning an existing persons row, or
  - creating a new persons row and mapping it immediately.

What it does not do:
- It does not run automatically when sacrament records are created.
- It does not automatically connect certificate requests to people.
- It does not auto-create a person row from a request submission.

It is a repair/aggregation layer, not an identity source of truth at creation time.

### 4. Sacrament Applications (Bookings)

Public applications are inserted in separate tables:
- [public/sacrament-applications/baptismal.php](../public/sacrament-applications/baptismal.php)
- [public/sacrament-applications/marriage.php](../public/sacrament-applications/marriage.php#L31-L184)

The shared application table is defined in [database/srs_sppo_schema.sql](../database/srs_sppo_schema.sql#L309-L343), with sacrament-specific application extension tables nearby.

How they flow today:
- Applications are created as a separate record type in sacrament_applications.
- A secretary reviews them in the admin queue.
- If the application is approved, the secretary still must manually encode the corresponding sacrament record into the actual sacrament register page:
  - baptismal register: [admin/records/baptismal.php](../admin/records/baptismal.php#L66-L165)
  - marriage register: [admin/records/marriage.php](../admin/records/marriage.php#L49-L118)

This is visible in the form logic:
- In [admin/records/baptismal.php](../admin/records/baptismal.php#L130-L165), if the record was created from an application, the system marks the application as completed after the register row is saved.
- In [admin/records/marriage.php](../admin/records/marriage.php#L90-L118), the same pattern is used.

This means the current application flow is not fully connected to the register flow. It is a separate workflow that is manually closed by the secretary.

### 5. Simple module map

Current state summary:

| Module | Reads | Writes | FK / link present | Manual step still required |
| --- | --- | --- | --- | --- |
| persons | Used by Person Mapper and profile views | person rows | Central identity table | None, but it is not populated automatically from new record creation |
| Baptismal Records | reads baptismal_records and baptismal_sponsors | inserts into baptismal_records | person_id, certificate_request_id | person_id assignment and matching to certificate requests are manual |
| Confirmation Records | reads confirmation_records | inserts into confirmation_records | baptismal_record_id, person_id, certificate_request_id | person_id assignment and matching are manual |
| First Communion Records | reads first_communion_records | inserts into first_communion_records | baptismal_record_id, person_id, certificate_request_id | person_id assignment and matching are manual |
| Marriage Records | reads marriage_records + marriage_witnesses | inserts into marriage_records | husband_person_id, wife_person_id, certificate_request_id | person_id assignment and matching are manual |
| Certificate Requests | reads certificate_requests + extension tables | inserts into shared request and extension tables | matched_record_id to register row, but no direct person_id or record_id subject FK | subject matching to canonical record is manual/separate |
| Sacrament Applications | reads sacrament_applications + extension tables | inserts into application tables | none to sacrament record table directly | approved application still needs a separate register entry |
| Person Mapper | reads persons + sacrament tables | updates person_id bridge columns | bridge columns only | manual assignment is required because no automatic mapping rule exists |

---

## Part 2 — Research summary and comparison with current system

Research focus: how established church/church-management systems organize family and sacramental data around a central person entity, instead of siloed sacrament tables.

### Source 1 — ParishSOFT Family Suite

Source summary:
- Website: ParishSOFT Family Suite / Families product overview
- Key language: “bring every family’s record and sacramental records into one powerful, web-based system,” and “sacramental records tracking” is described as part of the family directory.

What this tells us:
- The practical model is a family/person-centric system where sacramental events are not separate islands.
- The parishioner or family record is the anchor, and sacrament histories are attached to that central record.

Contrast with this codebase:
- The current system has the persons table and person_id bridges, but they are secondary and manual.
- The core record tables still duplicate person identity data in each row and do not automatically assign a shared parishioner entity at create time.

### Source 2 — ChurchTrac

Source summary:
- Website: ChurchTrac
- Key language: people/member database, centralized contact records, member profiles, single church database.

What this tells us:
- Established church systems keep one member/person record and attach events, ministries, sacramental history, giving, and family data to that entity.
- The database becomes relationally connected; not every event table reinventing the same person identity values.

Contrast with this codebase:
- This project is partly there via persons and person_id bridges, but the operational behavior still looks like a manual data-cleansing layer rather than a fully connected lifecycle.

### Source 3 — Breeze / Tithely ChMS

Source summary:
- Website: Breeze ChMS / Tithely Church Management
- Key language: member directories, contacts, church database, ease of use, central record being the basis for ministry and events.

What this tells us:
- Church systems treat a member/person as the durable entity, and all ministry records hang off that member identity.
- Event records and sacramental records are linked to a person record instead of re-creating the name each time.

Contrast with this codebase:
- The project already anticipates this principle with the persons table, but the day-to-day workflow still depends on manual mapping and manual matching, which makes the architecture feel disconnected despite the presence of the model pieces.

### Bottom-line research conclusion

Best-practice parish record systems keep one identity entity and connect sacramental events and certificate requests to it. The current codebase has the beginnings of this pattern, but it is not yet implemented as the default data-flow model.

---

## Part 3 — Proposed connected architecture (design only)

This is a design proposal only. It is not an implementation step.

### Design objective

Create one durable internal identity model for each person and point all related records at it:

- one persons row per person
- sacrament record rows reference persons via parishioner_id (or a dedicated person_id field)
- certificate requests reference the actual sacrament record or person being requested
- approved applications can create or link to the matching sacrament record automatically

This should not create public parishioner login accounts. The persons table remains an internal administrative identity index.

### Proposed relational model

Core identity:
- persons
  - id
  - first_name, middle_name, last_name, suffix
  - maiden_name
  - birth_date, birth_place
  - sex
  - contact/email/address
  - created_at, updated_at

Sacrament records should use a single person link rather than duplicate identity information:
- baptismal_records
  - id
  - person_id FK -> persons.id
  - baptism_date
  - baptism_place
  - book_no, page_no, line_no
  - other sacramental fields
- confirmation_records
  - id
  - person_id FK -> persons.id
  - confirmation_date
  - relevant confirmation fields
- first_communion_records
  - id
  - person_id FK -> persons.id
  - communion_date
  - relevant fields
- marriage_records
  - id
  - husband_person_id FK -> persons.id
  - wife_person_id FK -> persons.id
  - marriage_date
  - relevant fields

Certificate requests should store a real relationship to the canonical record or person, not just free text:
- certificate_requests
  - id
  - tracking_number
  - requester_person_id (optional, if the requester is a person already in the system)
  - requester_name (still may be kept for audit / display, but not the primary source of identity)
  - subject_person_id (optional) or subject_record_id
  - sacrament_type
  - sacrament_record_id (preferred actual FK in the canonical record table if the request is for an existing sacrament record)
  - matched_record_id (current code already has a version of this in extension tables)

For requests that are for a previously recorded sacrament, the best design is:
- certificate_requests.sacrament_record_id references the actual row in the correct sacrament table
- the subject is not stored only as a text string to be re-searched later
- the secretary can still confirm the match, but the relation exists as a proper foreign key from the start

### Proposed application-to-record flow

Applications should become a true source-to-record workflow:
- A public or onsite application creates a row in sacrament_applications.
- When approved, the next step is automatic record creation or record linking by the secretary/admin workflow.
- The application can pass prefilled values into a canonical register insert object, but the final row should still be the sacrament record itself.

In other words:
- application submission is the intake record
- approval is a workflow state
- record creation is a separate, deterministic result of approval
- the approved application should not remain a disconnected duplicate forever

### What would need migration vs what would be net-new

Migration work likely needed:
- Keep the existing persons table and add logic to make person_id assignment required for sacrament records after migration.
- Add or normalize foreign keys on baptismal_records, confirmation_records, first_communion_records, marriage_records.
- Add a direct subject linkage column to certificate_requests, or move the subject link into a dedicated certificate_request_subjects table if the system wants to handle multiple subject persons per request.
- Add a sacrament_record_id or person_id link to each certificate request and/or its extension table.
- Backfill existing rows from the current names in each sacrament table to the persons table before enforcing the relationship.

Net-new tables or tables that can remain as operational support:
- persons (already exists)
- a dedicated person_linking_audit or record_identity_audit table if the parish needs a log of every manual mapping
- a certificate_request_subjects table if the project later needs one-to-many subject lookup or multiple claimants per request

### Why this structure is better

- It eliminates duplicate identity entry across the four sacrament registers.
- It matches parish software best practices: family/person-centric records with sacramental history attached to the person.
- It reduces manual re-search and manual re-entry for every certificate request.
- It makes application approval and certificate request review more consistent, because the subject is linked to a canonical record instead of a one-off text string.

### Important implementation note before any coding

This design should be reviewed against the current project constraints before implementation:
- no public parishioner login accounts
- no online ID upload
- no public self-service identity verification
- the person table is internal only and should be treated as a registry layer rather than a user-auth layer

---

## Final assessment

The project is not completely disconnected, but it is only partially connected.

What exists today:
- A persons table exists.
- Some sacrament tables already include person_id or spouse-specific person fields.
- The Person Mapper is doing the cross-record linking work manually.
- The certificate generator has matched_record_id logic.

What is still disconnected:
- New sacrament rows do not automatically create or assign a person row.
- Certificate requests still capture the subject and requester as free text and not as clean foreign-key relationships.
- Applications remain separate from the official register records unless the secretary manually closes the loop.

In practical terms, the system already contains the conceptual building blocks for a connected parish register, but it still behaves like a set of siloed modules with a separate “linking” process layered on top.
