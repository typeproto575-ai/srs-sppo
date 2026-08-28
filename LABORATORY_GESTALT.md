# Gestalt Principles in SPPO-IS Admin System UI

## Principle: Proximity
UI:
- Admin system pages, with the account-management page as the visible proof of the shell
Location:
- `admin/system/accounts.php`
- Classes: `.admin-hero`, `.account-form`, `.panel-card`, `.admin-table`
Evidence:
- The account-management heading and its system description are grouped inside the hero area.
- The add/edit form and the existing user table are placed in adjacent panels.
- The table rows and account records are grouped inside the same data table structure.
Explanation:
- Grouping related controls and information closely together helps users understand that these elements belong to the same system workflow without reading every label.

## Principle: Similarity
UI:
- Shared system content blocks and status labels
Location:
- `admin/system/accounts.php`
- Classes: `.panel-card`, `.admin-table`, `.status-badge`, `.btn`
Evidence:
- Cards share rounded corners, borders, background treatment, and shadows.
- Status badges use the same capsule style across account status examples.
- Action links and form buttons use the same button language.
Explanation:
- Using the same visual styling for related components signals that they are part of the same system group, even when they are separated on the page.

## Principle: Closure
UI:
- System panels and form container
Location:
- `admin/system/accounts.php`
- Classes: `.panel-card`, `.admin-hero`, `.account-manager-card`
Evidence:
- Each panel is enclosed by visible borders, rounded corners, and background fills.
- The hero section forms a self-contained block that reads as a complete unit.
Explanation:
- Users mentally complete the implied boundaries of these sections, which helps them perceive organized blocks of information.

## Principle: Figure-Ground
UI:
- Header, sidebar, main system panel, and background
Location:
- `includes/admin-header.php`
- `admin/system/accounts.php`
- Classes: `.site-master-header`, `.admin-layout`, `.admin-sidebar`, `.admin-content`, `.panel-card`
Evidence:
- The dark header and sidebar stand out against the lighter page background.
- System cards and data tables appear as foreground elements over the softer radial admin background.
Explanation:
- Clear separation of foreground panels from the background directs attention to the interactive and informational surfaces.

## Note on Screenshot
- The screenshot should show the system UI in the admin area, using the account-management page as evidence of a working SPPO-IS admin-system screen.
- Use the location descriptions above to capture the correct UI regions.
