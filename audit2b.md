1. PASS — Inspected application controls; status options vary by workflow state, with review, person-link, checklist, and register actions available.
2. PASS — Checklist supports Not Received/Received/Verified with per-item save; no notes field; saved updates persisted.
3. FAIL — Baptism, First Communion, and Confirmation linked to #17; Marriage #36 prefilled parties/DOB/parents but left minister blank and emitted `$marriageDate` warnings (512/525). Screenshot: `audit2b-marriage-fail.png`.
4. PASS — Created fictional funeral record for Roy Ramos Mendoza; SSS claim print showed cause, profile omitted it, and public lookup returned no match.
5. FAIL — All four certificate previews rendered, but the Confirmation certificate has a blank sponsor shown as “—”. Screenshot: `audit2b-confirmation-fail.png`.
6. PASS — Dashboard counts and report tables were populated; no PHP warnings or empty report charts observed.
