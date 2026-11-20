# GitHub Copilot Instructions: Parish Management System

## System Context & Project Goal
This project is a **Parish Management & Digital Request System** handling four core Catholic sacraments and parish services: **Baptism, First Communion, Confirmation, and Marriage**.

The system consists of two primary operational interfaces:
1. **Public Online Web Portal:** For parishioners/clients to submit certificate requests (e.g., Baptismal Certificate), schedule parish services (e.g., Weddings), and receive updates from the Parish Office by email or by contacting the office with their reference number.
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
  - On the online confirmation screen and email notifications, display all required documents explicitly (e.g., Valid ID, Authorization Letter if picking up for someone else, Original Copy of External Documents).
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
- When writing workflow controllers, ensure request status transitions are explicit and log status updates for applicant email notifications and staff review.

## graphify

For any question about this repo's architecture, structure, components, or how to add/modify/find
code, your first action should be `graphify query "<question>"` when `graphify-out/graph.json`
exists. Use `graphify path "<A>" "<B>"` for relationship questions and `graphify explain "<concept>"`
for focused-concept questions. These return a scoped subgraph, usually much smaller than the full
report or raw grep output.

Triggers: "how do I…", "where is…", "what does … do", "add/modify a <component>",
"explain the architecture", or anything that depends on how files or classes relate.

If `graphify-out/wiki/index.md` exists, use it for broad navigation. Read `graphify-out/GRAPH_REPORT.md`
only for broad architecture review or when query/path/explain do not surface enough context. Only read
source files when (a) modifying/debugging specific code, (b) the graph lacks the needed detail, or
(c) the graph is missing or stale.

Type `/graphify` in Copilot Chat to build or update the graph.
