# Parish Sacramental Records and Certificate Request System — Implementation Specification

**St. Patrick's Parish, Dingalan, Aurora — Capstone Project**

Developed and tested locally using **XAMPP** and **VS Code**. Not deployed online for now.

---

## Tech Stack

- Frontend: **HTML, CSS, JavaScript** (vanilla; minimal libraries if needed)
- Backend: **PHP** (plain, no heavy framework)
- Database: **MySQL/MariaDB** via **XAMPP**
- Environment: **VS Code** + **XAMPP** (localhost only)
- Authentication:
  - **Parish Secretary / Admin**: full account with username + password
  - **Parishioners (non-account users)**: no full registration; they only provide their **Gmail address** when requesting a certificate, to receive a **reference number** and track request status.

## Project Goal

Build a **local web system** that:

1. Allows the **parish secretary** to:
   - Record and manage sacramental and related parish records.
   - Generate printable certificates from those records.
   - Manage certificate requests from parishioners (view, update status, mark as "ready for pickup").

2. Allows **parishioners** to:
   - Submit **certificate requests** online (fill a form, provide Gmail, get a reference number).
   - Track the **status** of their request using that reference number + Gmail.
   - See **requirements** for each sacrament or service.

3. **Certificates are only released at the parish office** (no delivery, no online download of official sealed certificates).

The system is for **testing and demonstration** only (capstone), but should be designed as if it could be used in a real parish.

---

## Users & Roles

### 1. Parish Secretary / Admin

- Has a **login account** (username + password stored in DB).
- Can access an **admin dashboard** with modules for:
  - Sacramental Records (Baptism, Confirmation, First Communion, Marriage, Death/Burial, etc.)
  - Parishioner / Family Records
  - Catechism / Formation Records
  - Mass & Liturgical Scheduling (optional but nice to have)
  - Certificate Requests Management
  - User management (other secretaries, priests, etc., if needed)

Main capabilities:

- Create, view, update, and (logically) delete records.
- Generate and print certificates from existing records.
- View incoming certificate requests, verify details, and update status:
  - `Pending` → `Processing` → `Ready for Pickup` → `Claimed` / `Cancelled`
- Mark when a certificate has been **claimed** at the parish office.

### 2. Parishioners (Public / Non-Account Users)

- Do **not** create full accounts.
- When requesting a certificate:
  - Fill out an online form.
  - Provide **Gmail address**.
  - System generates a **reference number**.
  - They use **reference number + Gmail** to check status on a "Track Request" page.
- Can:
  - View **requirements** for each sacrament or certificate type.
  - Submit a **certificate request**.
  - Track request status.
- Cannot:
  - Download official certificates.
  - Edit parish records.
  - Access admin functions.

---

## Core Modules to Implement

### 1. Sacramental Records Module

Support at least these sacraments/records:

1. **Baptism**
2. **Confirmation**
3. **First Holy Communion**
4. **Holy Matrimony (Church Wedding / Convalidation)**
5. **Death / Burial**
6. (Optional) **Anointing of the Sick** (internal record only, no certificate)
7. (Optional) **Holy Orders** (if relevant; usually diocesan, but can be supported)

For each sacrament, the system must:

- Store all necessary fields in the database (see "Data Fields" below).
- Allow the secretary to:
  - Add new records.
  - Search/view existing records.
  - Edit records (with audit trail if possible).
  - Generate a **printable certificate** (HTML → print / PDF via browser print).

#### Example Data Fields (adjust as needed)

**Baptism**

- Full name (at baptism / current name)
- Date of birth
- Place of birth
- Sex
- Names of parents (father, mother)
- Names of godparents (sponsors)
- Date of baptism
- Place of baptism (parish, church)
- Minister (priest/deacon)
- Notes (e.g., conditional baptism, reception into full communion)
- Later notations: confirmation, marriage, holy orders, death (at least flags or linked records)

**Confirmation**

- Full name
- Date & place of birth
- Names of parents
- Names of sponsors
- Date & place of confirmation
- Minister (bishop or delegated priest)
- Reference to baptismal record (parish, book/page/entry if you want)

**First Holy Communion**

- Full name
- Date & place of birth
- Date & place of First Communion
- Parish/church
- Presiding priest (optional)
- Reference to baptism/confirmation

**Marriage**

- Full names of bride & groom
- Date & place of birth (each)
- Names of parents (each)
- Date & place of marriage
- Parish/church of celebration
- Witnesses & sponsors
- Type of marriage (canonical form, convalidation, mixed religion, etc.)
- Pre-nuptial investigation summary (brief text)
- Link to baptismal records of both spouses (at least by name/parish)

**Death / Burial**

- Full name of deceased
- Date & place of death
- Age at death
- Sex
- Name of spouse
- Names of surviving children (optional)
- Names of parents (optional)
- Date & place of burial
- Cemetery/plot location (if applicable)

You can normalize these into separate tables (e.g., `baptisms`, `confirmations`, `marriages`, `deaths`, etc.) with a shared `persons` table if you want a cleaner design.

### 2. Certificate Generation Module

For each sacrament/record type that normally has a certificate:

- Baptism → **Baptismal Certificate**
- Confirmation → **Confirmation Certificate**
- First Communion → **First Holy Communion Certificate** (optional)
- Marriage → **Parochial Marriage Certificate**
- Death/Burial → **Death/Burial Certificate** (parochial)

Requirements:

- Certificates are generated **from existing records only** (no certificate without a record).
- Certificates are:
  - Displayed in a printable HTML layout.
  - Meant to be printed on parish letterhead or with parish seal image.
  - Include:
    - Parish name, address, contact
    - Title (e.g., "Baptismal Certificate")
    - All relevant non-confidential data from the record
    - Space for signature and dry seal (can be an image placeholder)
    - Date of issuance
    - Optional: QR code or reference number for internal verification (bonus feature).
- Certificates are **not downloadable by parishioners** in this version; only the secretary can generate/print them.

### 3. Certificate Request Module (Public-Facing)

This is what parishioners interact with.

#### Public Pages

1. **Home / Landing Page**
   - Brief description of the system.
   - Links to:
     - "Request a Certificate"
     - "Track Request"
     - "Sacrament Requirements"

2. **Sacrament Requirements Page**
   - Lists requirements for:
     - Baptism (for infants, adults)
     - Confirmation
     - First Communion
     - Marriage (church wedding)
     - Death/Burial arrangements (if relevant)
   - Include typical Philippine parish requirements, e.g.:

   **Baptism (Infant)**

   - PSA Birth Certificate of child
   - PSA Marriage Certificate of parents (if married)
   - Valid IDs of parents (and godparents, if required)
   - Certificate of Sponsorship / Godparent eligibility (if required by parish)
   - Baptismal seminar attendance (parents & godparents)

   **Confirmation**

   - PSA Birth Certificate
   - Baptismal Certificate
   - Proof of completion of confirmation catechesis
   - Valid ID(s); for minors, parent/guardian consent

   **Marriage**

   - Latest Baptismal Certificate "For Marriage Purposes" (valid 3–6 months)
   - Confirmation Certificate "For Marriage Purposes"
   - PSA Marriage License
   - CENOMAR from PSA
   - Pre-Cana / Family Life seminar certificate
   - List of sponsors
   - Valid IDs of both parties

   **Death / Burial**

   - Death Certificate (PSA or local civil registrar)
   - Valid ID of requesting family member
   - If parish cemetery: proof of plot ownership (if applicable)

   You can refine these based on typical Philippine parish practice.

3. **Request Certificate Page**
   - Form fields:
     - Certificate type (dropdown: Baptismal, Confirmation, First Communion, Marriage, Death/Burial, Membership, etc.)
     - Full name of person on record
     - Date of birth
     - Place of birth (city/municipality, province)
     - Names of parents (for baptism/confirmation/marriage)
     - Year (or exact date if known) of sacrament/event
     - Parish where sacrament/event occurred (default: St. Patrick's Parish, Dingalan, Aurora; but allow "Other" if needed)
     - Purpose of request (e.g., "For Marriage", "For School", "For Employment", "For Personal Use")
     - Requester's full name
     - Requester's Gmail address (required)
     - Requester's contact number (optional)
     - Relationship to person on record (self, child, spouse, sibling, etc.)
   - On submit:
     - Create a record in `certificate_requests` table.
     - Generate a **reference number** (e.g., `SPD-2026-000123`).
     - Show the reference number on-screen.
     - Display instructions:
       - "Your certificate can only be claimed at the parish office."
       - "Use your reference number and Gmail to track your request."

4. **Track Request Page**
   - Form fields:
     - Reference number
     - Gmail address
   - On submit:
     - Show current status:
       - `Pending`
       - `Processing`
       - `Ready for Pickup`
       - `Claimed`
       - `Cancelled`
     - Show basic info:
       - Certificate type
       - Name on record
       - Date requested
       - Remarks (optional, e.g., "Please bring valid ID when claiming.")

### 4. Admin Dashboard (Parish Secretary)

Protected by login.

Main sections:

1. **Dashboard Overview**
   - Summary counts:
     - Total sacramental records per type
     - Pending certificate requests
     - Requests ready for pickup
     - Requests claimed today/this week

2. **Sacramental Records Management**
   - Separate pages/sections for:
     - Baptisms
     - Confirmations
     - First Communion
     - Marriages
     - Deaths/Burials
   - For each:
     - List view (searchable, filterable by name, year, etc.)
     - Add new record form
     - View/edit record form
     - Button: "Generate Certificate" → opens printable certificate view.

3. **Certificate Requests Management**
   - List of all requests with:
     - Reference number
     - Certificate type
     - Name on record
     - Requester name & Gmail
     - Date requested
     - Status
   - Actions:
     - View details
     - Update status (dropdown + save)
     - Add internal remarks (e.g., "Missing baptismal book info; checking archive.")
     - Mark as "Ready for Pickup"
     - Mark as "Claimed" (with date/time and secretary name)

4. **User Management (Admin)**
   - Manage accounts for:
     - Parish secretaries
     - Priests (view-only, if needed)
   - Simple CRUD for admin users.

5. **Settings (Optional)**
   - Parish info (name, address, contact, logo/seal image).
   - Certificate templates (basic text configuration).
   - Reference number format settings.

---

## Database Design (High-Level)

You can ask the AI to propose a concrete schema, but here's the outline:

- `users`
  - id, username, password_hash, role (admin/secretary/priest), created_at

- `persons` (optional, for normalization)
  - id, full_name, date_of_birth, place_of_birth, sex, created_at

- `baptisms`
  - id, person_id (or direct fields), father_name, mother_name, godparents (JSON or separate table), date_of_baptism, place_of_baptism, minister, notes, created_at, updated_at

- `confirmations`
  - id, person_id, sponsors, date_of_confirmation, place_of_confirmation, minister, baptism_reference, etc.

- `first_communion`
  - id, person_id, date, place, presiding_priest, etc.

- `marriages`
  - id, groom_person_id, bride_person_id, date_of_marriage, place_of_marriage, parish_of_marriage, witnesses, type, pre_nuptial_summary, etc.

- `deaths`
  - id, person_id, date_of_death, place_of_death, age_at_death, spouse_name, children_names, parents_names, date_of_burial, burial_place, plot_info, etc.

- `certificate_requests`
  - id, reference_number, certificate_type, person_name, person_dob, person_pob, parents_names, sacrament_year, sacrament_parish, purpose, requester_name, requester_gmail, requester_contact, relationship, status (enum), remarks, created_at, updated_at

- `parish_settings` (optional)
  - id, parish_name, address, contact, logo_path, seal_image_path, etc.

You can let the AI refine this into actual `CREATE TABLE` statements.

---

## Functional Requirements Summary

The system must:

1. Run on **localhost** via XAMPP.
2. Use **HTML, CSS, JS, PHP, MySQL** only (no heavy frameworks).
3. Provide:
   - Public pages for:
     - Viewing sacrament requirements
     - Submitting certificate requests
     - Tracking request status (reference number + Gmail)
   - Admin pages (login-protected) for:
     - Managing sacramental records
     - Generating certificates (printable)
     - Managing certificate requests and statuses
4. Enforce that:
   - Certificates are generated **only by logged-in secretaries**.
   - Parishioners **cannot download** certificates; they can only request and track.
   - Certificates are **claimed at the parish office** (no delivery option).
5. Include all typical **sacrament requirements** in the "Requirements" page so parishioners know what to prepare before coming to the office.

---

## Implementation Checklist

1. Propose a **database schema** (SQL) based on this description.
2. Generate **folder structure** for the project (e.g., `admin/`, `public/`, `includes/`, `assets/`, etc.).
3. Create:
   - Basic **config/db.php** for DB connection.
   - Sample **login system** for admin (session-based).
   - Public pages:
     - `index.php` (home)
     - `requirements.php` (sacrament requirements)
     - `request.php` (certificate request form)
     - `track.php` (track request)
   - Admin pages:
     - `admin/index.php` (dashboard)
     - `admin/records/` (CRUD for each sacrament)
     - `admin/requests/` (manage certificate requests)
     - `admin/certificates/` (generate/print certificates)
4. Implement:
   - Reference number generation logic.
   - Status workflow for requests.
   - Printable certificate templates (HTML + CSS).
5. Suggest **security basics** for a capstone (e.g., password hashing, prepared statements, basic input validation).

Start by proposing the **database schema and project structure**, then implement page by page.