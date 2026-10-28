# GitHub Copilot Instructions: Parish Management System

## System Context & Project Goal
This project is a **Parish Management & Digital Request System** handling four core Catholic sacraments and parish services: **Baptism, First Communion, Confirmation, and Marriage**.

The system consists of two primary operational interfaces:
1. **Public Online Web Portal:** For parishioners/clients to submit certificate requests (e.g., Baptismal Certificate), schedule parish services (e.g., Weddings), and track request status online.
2. **Parish Office Admin/Secretary Interface:** For parish staff to manage, verify, process, generate, and track requests, payments, document requirements, and signatures.

---

## Key Architectural & Business Requirements

### 1. Unified Master Database Architecture (CRITICAL)
- **Current Issue:** The legacy/initial schema used 4 isolated databases/tables for Baptism, First Communion, Confirmation, and Marriage without cross-referencing.
- **Required Architecture:** Re-architect into a **Single Master Database** with a centralized `Person` / `Parishioner` core entity.
- **Entity Linking:**
  - A person is a single entity across all life events (`Baptism` → `First Communion` → `Confirmation` → `Marriage`).
  - When creating or requesting any sacrament/certificate, search the master `Person` database first.
- **Auto-Complete / Lookup Functionality:**
  - Implement real-time search/auto-complete on name input fields.
  - Selecting an existing person auto-fills core biographical data (full name, date of birth, parents' names), leaving only sacrament-specific fields to be completed (e.g., sponsors/godparents, officiating priest, event date/location).

### 2. Streamlined Request & Tracking Workflow
- **Upfront Document Requirements:**
  - On the online confirmation screen, tracking portal, and email notifications, display all required documents explicitly (e.g., Valid ID, Authorization Letter if picking up for someone else, Original Copy of External Documents).
  - Do not surprise clients with unstated document requirements when they arrive at the office.
- **Status Lifecycle:**
  - Maintain a clean request lifecycle with unambiguous statuses:
    - `Submitted / Pending Review`
    - `Action Required: Documents Missing`
    - `Awaiting Payment` (On-site or online)
    - `Under Processing / Pending Priest Signature`
    - `Ready for Pickup`
    - `Completed / Issued`
- **Avoid Processing Bottlenecks:**
  - Ensure the online request portal reduces turnaround time. Allow staff to pre-verify details before client arrival so clients visit only for payment and pickup.

### 3. Business Logic & Interface Rules
- **Requester Relationship Dropdown:**
  - Support choices: `Self`, `Parent`, `Sibling`, `Child`, `Authorized Representative`.
  - When `Self` is selected, auto-fill applicant fields from the user's profile and flag that a Valid ID matching the record is required upon pickup.
- **External / Out-of-Parish Applicants:**
  - Handle parishioners who completed prior sacraments at external/other parishes (e.g., baptized in another province).
  - Include fields and status gates for external documentation: Origin Parish Transfer/Permission Letter, Origin Parish Baptismal Certificate, and PSA CENOMAR for Marriage applications.

### 4. Database Seeding & Testing
- Pre-populate database seed files with complete mock datasets (realistic parishioner records spanning all 4 sacraments) for smooth testing and live system demonstrations.

---

## Coding Guidelines for Copilot
- When writing database migrations, models, or schema designs, prioritize a **normalized relational structure** centered on a unified `Person` model.
- When generating UI forms or API endpoints, enforce **auto-complete/search endpoints** to prevent duplicate data entry.
- When writing workflow controllers, ensure request status transitions are explicit and log status updates for customer-facing tracking.
