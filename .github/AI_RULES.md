# SRS-SPPO: rules for AI assistants (Cline, Copilot)

Place this same file at `.clinerules` (Cline) and `.github/copilot-instructions.md` (Copilot).

## 1. Project

Saint Patrick's Parish Schedule and Record System. Native PHP 8, MySQL/MariaDB (XAMPP locally), HTML/CSS/JS.
Goal: the secretary must be **faster at the counter than the paper logbook**. If a change adds typing, clicks or screens, it is probably wrong.
Work is local first (branch `rebuild-v2`). The hosted site (`stpatricksparish.me`) is updated later, not now.

## 2. Source of truth

1. `database/v2/schema.sql` is the only database definition. 12 tables.
2. `database/v2/DATA_DICTIONARY.md` explains it. If the two disagree, `schema.sql` wins.
   `database/v2/seed_demo.sql` is the demo data (fictional people, login `secretary` / `demo1234`, local only).
3. `project-ai-handover-guide.md` describes the OLD schema. Use it only for business intent, never for table or column names.
4. There are **no migrations**. To change the database, edit `schema.sql` and `DATA_DICTIONARY.md` together and say so. Never add numbered migration files or an in-app upgrader.
5. Never create a new table without being asked. If you think one is needed, stop and ask.

## 3. Old name → new name

| Old (do not use) | v2 |
|---|---|
| `baptismal_records`, `first_communion_records`, `confirmation_records`, `marriage_records` | `sacrament_records`, filtered by `sacrament_type` |
| `husband_person_id`, `wife_person_id` | `person_id` (groom), `spouse_person_id` (bride) |
| `baptismal_sponsors`, `marriage_witnesses`, `marriage_principal_sponsors` | `sacrament_participants` with `role` |
| `certificate_generation_log` | `certificate_requests` (`claimant_type`, `representative_*`, `id_verified`, `released_*`); reprints go to `audit_logs` |
| `matched_record_id` | `certificate_requests.record_id` |
| `workflow_requirements` | `sacrament_applications.requirements` (JSON) |
| `sacrament_eligibility_settings`, `site_settings` | `settings` (key/value) |
| `email_delivery_log` | `notifications` with `channel = 'email'` |
| `password_reset_tokens` | `users.reset_token_hash`, `reset_expires_at` |
| `baptismal_record_id` link | look for a baptism row in `sacrament_records` |
| tracking number / public tracking page | removed. `request_no` is an internal reference only |

## 4. Hard rules

1. **Identity lives only in `persons`** (name, birth, parents, address, contact). Register and application forms show it read-only for an existing person. Never copy identity fields into another table.
2. **Public forms write only to `sacrament_applications` or `certificate_requests`.** They never write to `persons` or `sacrament_records`. Only the secretary's approval does.
3. **`sacrament_records.legitimacy` is internal.** Never select it into any public page, email, certificate, PDF, JSON response for the public site or default staff list. New entries store NULL: staff never type it and it is NOT derived from `parents_union_type`.
4. **Certificates print only** for `source = 'this_parish'` records with `book_verified_at` set.
5. **Release requires** `claimant_type` and `id_verified = 1`. A representative also needs `representative_name`.
6. **The Person Matcher widget stays** on the application and online-request review screens. Search `persons` by surname and birth date; link in one click; allow two links for marriage (groom and bride).
7. **Prefill is one click.** "Prefill Register Form" carries `from_application=<id>` plus the linked `person_id`s into the register form. No retyping of identity.
8. **No money amounts anywhere.** Payment is the manual `paid_at` / `paid_by` stamp, recorded at the counter before processing: matching is refused until `paid_at` is set, and release also requires it.
9. **Unknown means NULL.** Do not add "not recorded" flags.
10. **Sacrament records are never purged.** Retention only sets `anonymized_at` on old requests.
11. **Profile query** for one person: `WHERE person_id = ? OR spouse_person_id = ?`.

## 5. Flows

**Intake → register:** public submit (application) → secretary opens review → Person Matcher links `person_id` (or creates a new `persons` row) → status moves along → Prefill Register Form → secretary saves a `sacrament_records` row → application `record_id` set, status `completed`.

**Certificate:** `new` (payment is recorded first, before any processing) → `matched` (person and record linked, book entry verified) → `for_signature` (printed) → `ready` (priest signed on paper, `signed_at`, "ready" email) → `released` (counter ID check). `rejected` is allowed before release.

**Two-stage identity check:** Stage 1 is the online relationship choice plus the upfront pickup notice ("Valid Government ID on pickup; a representative also brings an authorization letter and their own ID"). Stage 2 is the counter check recorded in `certificate_requests`.

**Application statuses:** `new` → `reviewing` ⇄ `requirements_pending` → `approved` → `scheduled` → `completed`; `rejected` before completion.

## 6. Parents' union labels (baptism form)

Staff see "Parents' marital status". Store in `parents_union_type`:

| Staff label | Stored value |
|---|---|
| Church married | `legitimate` |
| Civilly married | `civil` |
| Unmarried | `natural` |

`legitimacy` is a separate internal column. **Always store NULL.** Do not derive it from the union type, do not add a dropdown for it, do not select it anywhere except an internal staff record view if one is ever needed.

## 7. Prerequisites: flag, never block

First Communion needs a baptism row (or `persons.baptismal_status = 'baptized_catholic'`). Confirmation needs baptism and first communion. Marriage needs baptism for both, one party a registered parishioner, plus CENOMAR, origin baptismal certificate, and a transfer letter if marrying outside the home parish. Show a clear warning to the secretary. Do not stop her from saving.
Parishes do not share databases: other-parish history is entered as `source = 'other_parish'` from the origin certificate. No certificate is printed for those.

## 8. Not in scope: do not build

- Tracking-number lookup or any public status page.
- Document uploads (documents are shown in person only).
- The secretary handover flow beyond the existing forgot-password token. It is a described design only.
- Roles other than `secretary`.
- Anything on the hosted server.

## 9. Working style

- Before big changes, state the plan in a few lines and wait if it touches the schema, a status flow or a privacy rule.
- Reuse the existing helpers instead of writing new ones: the shared `$pdo` from `config/database.php`, `csrf_field()` and `csrf_verify()` for forms, `require_login()` and `require_role()` for access, `e()` to escape output. Look at a neighbouring page first and copy its pattern.
- Prepared statements for every query. Hash passwords with `password_hash()`.
- After editing a PHP file, run `php -l <file>` and fix any error before moving on.
- Do not extend or build on the old tools that are being removed: `includes/database-upgrades.php`, `includes/retention-purge.php`, the person mapper, and the tracking page.
- Keep screens and forms few. Prefer reusing an existing screen over adding one.
- All demo emails use `example.com`. Never send real mail from demo data.
- Small commits on `rebuild-v2`. Never touch the old schema files in place; v2 lives in `database/v2/`.
