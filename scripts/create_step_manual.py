from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.enum.section import WD_SECTION
from docx.shared import Inches, Pt, RGBColor
from docx.oxml import OxmlElement
from docx.oxml.ns import qn


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "docs" / "SRS-SPPO_Step-by-Step_User_Manual_v2.0.docx"


def set_cell_shading(cell, fill):
    tc_pr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:fill"), fill)
    tc_pr.append(shd)


def set_repeat_table_header(row):
    tr_pr = row._tr.get_or_add_trPr()
    tbl_header = OxmlElement("w:tblHeader")
    tbl_header.set(qn("w:val"), "true")
    tr_pr.append(tbl_header)


def add_field(paragraph, instruction):
    run = paragraph.add_run()
    fld_char = OxmlElement("w:fldChar")
    fld_char.set(qn("w:fldCharType"), "begin")
    run._r.append(fld_char)
    instr_text = OxmlElement("w:instrText")
    instr_text.set(qn("xml:space"), "preserve")
    instr_text.text = instruction
    run._r.append(instr_text)
    fld_char = OxmlElement("w:fldChar")
    fld_char.set(qn("w:fldCharType"), "end")
    run._r.append(fld_char)


def add_title_page(doc):
    section = doc.sections[0]
    section.top_margin = Inches(0.75)
    section.bottom_margin = Inches(0.75)
    section.left_margin = Inches(0.85)
    section.right_margin = Inches(0.85)

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_after = Pt(18)
    run = p.add_run("SRS-SPPO")
    run.bold = True
    run.font.size = Pt(30)
    run.font.color.rgb = RGBColor(31, 94, 58)

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_after = Pt(8)
    run = p.add_run("Step-by-Step User Manual")
    run.bold = True
    run.font.size = Pt(22)

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = p.add_run("Schedule and Record System")
    run.font.size = Pt(14)
    run.font.color.rgb = RGBColor(85, 85, 85)

    doc.add_paragraph("")
    table = doc.add_table(rows=4, cols=2)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.autofit = False
    table.columns[0].width = Inches(1.55)
    table.columns[1].width = Inches(4.5)
    entries = [
        ("Organization", "St. Patrick's Parish Office"),
        ("Version", "2.0"),
        ("Primary users", "Public applicants and Parish Office secretary"),
        ("Document purpose", "Task-by-task operating instructions"),
    ]
    for row, (label, value) in zip(table.rows, entries):
        row.cells[0].text = label
        row.cells[1].text = value
        set_cell_shading(row.cells[0], "EAF3ED")
        row.cells[0].vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
        row.cells[1].vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
        for cell in row.cells:
            for para in cell.paragraphs:
                para.paragraph_format.space_after = Pt(4)
                para.paragraph_format.space_before = Pt(4)

    doc.add_page_break()


def add_heading(doc, text, level=1):
    return doc.add_heading(text, level=level)


def add_step(doc, number, title, details):
    table = doc.add_table(rows=1, cols=2)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.autofit = False
    table.columns[0].width = Inches(0.55)
    table.columns[1].width = Inches(6.25)
    number_cell = table.cell(0, 0)
    content_cell = table.cell(0, 1)
    set_cell_shading(number_cell, "1F5E3A")
    set_cell_shading(content_cell, "F4F7F5")
    number_cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
    content_cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
    number_para = number_cell.paragraphs[0]
    number_para.alignment = WD_ALIGN_PARAGRAPH.CENTER
    number_run = number_para.add_run(str(number))
    number_run.bold = True
    number_run.font.size = Pt(13)
    number_run.font.color.rgb = RGBColor(255, 255, 255)

    title_para = content_cell.add_paragraph()
    title_para.paragraph_format.space_after = Pt(3)
    title_run = title_para.add_run(title)
    title_run.bold = True
    title_run.font.size = Pt(11)
    title_run.font.color.rgb = RGBColor(31, 94, 58)

    for detail in details:
        para = content_cell.add_paragraph(style="List Bullet")
        para.paragraph_format.space_after = Pt(2)
        para.add_run(detail)

    doc.add_paragraph("")


def add_warning(doc, title, text):
    table = doc.add_table(rows=1, cols=1)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    set_cell_shading(table.cell(0, 0), "FFF4D6")
    p = table.cell(0, 0).paragraphs[0]
    r = p.add_run(title + ": ")
    r.bold = True
    p.add_run(text)
    doc.add_paragraph("")


def add_task_intro(doc, purpose, where):
    table = doc.add_table(rows=2, cols=2)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.autofit = False
    table.columns[0].width = Inches(1.25)
    table.columns[1].width = Inches(5.55)
    table.cell(0, 0).text = "Purpose"
    table.cell(0, 1).text = purpose
    table.cell(1, 0).text = "Where"
    table.cell(1, 1).text = where
    for row in table.rows:
        set_cell_shading(row.cells[0], "EAF3ED")
        row.cells[0].paragraphs[0].runs[0].bold = True
        for cell in row.cells:
            cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
    doc.add_paragraph("")


def main():
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    doc = Document()

    styles = doc.styles
    styles["Normal"].font.name = "Aptos"
    styles["Normal"].font.size = Pt(10)
    styles["Title"].font.name = "Aptos Display"
    styles["Heading 1"].font.name = "Aptos Display"
    styles["Heading 1"].font.size = Pt(16)
    styles["Heading 1"].font.color.rgb = RGBColor(31, 94, 58)
    styles["Heading 2"].font.name = "Aptos Display"
    styles["Heading 2"].font.size = Pt(13)
    styles["Heading 2"].font.color.rgb = RGBColor(31, 94, 58)

    add_title_page(doc)

    add_heading(doc, "Contents", 1)
    toc = doc.add_paragraph()
    add_field(toc, 'TOC \\o "1-2" \\h \\z \\u')
    doc.add_page_break()

    add_heading(doc, "1. Start Here", 1)
    add_heading(doc, "1.1 Sign in", 2)
    add_task_intro(doc, "Open the system and enter a staff account.", "Public users do not sign in. Secretary staff use the staff login page.")
    add_step(doc, 1, "Open the Staff Login page.", ["Enter the hosted staff address supplied by the parish.", "Bookmark or save the address only after the parish confirms it."])
    add_step(doc, 2, "Enter username and password.", ["Use the assigned secretary account.", "Do not share the account or password."])
    add_step(doc, 3, "Select Log In.", ["If the page says the account is inactive, ask the parish system administrator to reactivate it.", "Wait if too many failed login attempts are blocked."])

    add_heading(doc, "1.2 Open a menu", 2)
    add_step(doc, 1, "Use the left sidebar.", ["Requests = applications and certificate requests.", "People & Records = person profiles and sacramental records.", "Schedule = calendar, announcements, and schedule checks.", "System = accounts, audit, backup, and eligibility tools."])
    add_step(doc, 2, "Open Notifications.", ["Use the bell button to read new work or status notices.", "Refresh the page after completing a posted task."])

    add_heading(doc, "2. Public Applicants", 1)
    add_heading(doc, "2.1 Open the public portal", 2)
    add_task_intro(doc, "Submit a sacraments or certificate request without a staff account.", "Public Office Services page.")
    add_step(doc, 1, "Open Office Services.", ["Choose Sacraments for Baptism, First Communion, Confirmation, or Marriage.", "Choose Certificates for a copy of an existing parish sacramental record."])
    add_step(doc, 2, "Read the requirements notice.", ["Review the documents and preparation information shown for the selected service.", "Do not upload or email documents through the public form unless the Parish Office specifically directs otherwise."])

    add_heading(doc, "2.2 Submit a sacrament pre-application", 2)
    add_step(doc, 1, "Open the selected sacrament form.", ["Complete the applicant or couple information.", "Use names and dates exactly as shown on supporting documents."])
    add_step(doc, 2, "Provide parent, birth, and contact details.", ["Use the correct email address and phone number for status updates.", "If a different representative or pickup contact should be used, enter the person relationship and contact details requested by the form."])
    add_step(doc, 3, "Enter the Marriage proposal details if applicable.", ["Select St. Patrick's Parish or Other venue.", "Enter the preferred date and time.", "A preferred date is a request and does not reserve or confirm a wedding."])
    add_step(doc, 4, "Review and submit.", ["Check every field before selecting Submit.", "Save the displayed reference number.", "Record the same reference number if the family contacts the office."])
    add_warning(doc, "Submission only", "Submitting an application does not approve documents, confirm preparation, reserve a date, or create an official register entry.")

    add_heading(doc, "2.3 Request a certificate", 2)
    add_step(doc, 1, "Open Request a Certificate.", ["Choose Baptism, First Communion, Confirmation, or Marriage.", "Enter the sacrament subject's name and relevant details."])
    add_step(doc, 2, "Enter requester information.", ["Select the relationship to the person named on the certificate.", "Enter a working email address and, when requested, a contact number."])
    add_step(doc, 3, "Review the pickup requirements.", ["For a representative, review the authorization-letter requirement.", "For an external or other-parish record, review the origin-parish document requirements."])
    add_step(doc, 4, "Submit and save the reference.", ["Keep the certificate reference number.", "Use it when checking the status or contacting the office."])

    add_heading(doc, "2.4 View or track public requests", 2)
    add_step(doc, 1, "Open View My Requests.", ["Enter the public reference number.", "Enter the email address used on the request."])
    add_step(doc, 2, "Request the one-time access code.", ["Check the email inbox for the verification code.", "Enter the code before it expires."])
    add_step(doc, 3, "Review the current status.", ["Read the displayed status and any office note.", "Do not visit or make payment until the office instructs the requester to do so."])
    add_step(doc, 4, "Contact the Parish Office when needed.", ["Use the displayed or known reference number.", "Do not send identity or sacramental documents through an unapproved public email or messenger workflow."])

    add_heading(doc, "3. Find a Person or Request", 1)
    add_heading(doc, "3.1 Search for a person", 2)
    add_task_intro(doc, "Search before creating a new master person profile.", "People & Records > Persons or the dashboard person search.")
    add_step(doc, 1, "Open Person Search.", ["Enter first name, surname, or birth date.", "Review possible matches carefully."])
    add_step(doc, 2, "Compare identity details.", ["Compare birth date, parent names, address, and existing sacramental records.", "A matching name alone is not proof of identity."])
    add_step(doc, 3, "Open or create the profile.", ["Open the correct profile if it exists.", "Create a profile only after the person cannot be matched safely."])

    add_heading(doc, "3.2 Search a sacrament request", 2)
    add_task_intro(doc, "Locate a pre-application or certificate request.", "Requests > Sacrament Pre-Applications or Requests > Certificate Requests.")
    add_step(doc, 1, "Use Search.", ["Enter the public reference number, applicant name, or subject name.", "Use spaces exactly only when needed."])
    add_step(doc, 2, "Apply filters.", ["Filter by sacrament, status, submitted channel, date, or assigned staff.", "Clear filters before searching for an item that may not appear."])
    add_step(doc, 3, "Open Review.", ["Review the submitted details before changing status or matching records.", "Keep the reference number for notes and staff communication."])

    add_heading(doc, "4. Process a Sacrament Pre-Application", 1)
    add_task_intro(doc, "Review, verify, approve, schedule, and complete a submitted application.", "Requests > Sacrament Pre-Applications.")
    add_warning(doc, "Important", "An application is not approved until staff saves the next status and a schedule is not confirmed until the final calendar process is complete.")

    add_heading(doc, "4.1 Match applicant profiles", 2)
    add_step(doc, 1, "Open the application.", ["Select Review for the correct reference number.", "Read the submitted names and details before matching."])
    add_step(doc, 2, "Match one applicant.", ["Search the master person index.", "Select the existing person when the identity matches.", "Create or link a profile if the person is not recorded."])
    add_step(doc, 3, "Match the other applicant for Marriage.", ["Match the groom and bride separately.", "Do not link both roles to the same profile."])
    add_warning(doc, "Identity check", "Confirm birth date and parent names where available. Do not link by name alone.")

    add_heading(doc, "4.2 Complete requirements", 2)
    add_step(doc, 1, "Open the Requirements checklist.", ["Check each document or preparation item for the sacrament."])
    add_step(doc, 2, "Choose one status.", ["Not Received = the item is still missing.", "Received = the office has the item but final verification is still needed.", "Verified = the item is checked and accepted."])
    add_step(doc, 3, "Save the checklist item.", ["Review the remaining missing items before approval.", "Do not mark an item Verified without checking the source."])

    add_heading(doc, "4.3 Correct wrong submitted data", 2)
    add_step(doc, 1, "Wait until requirements are complete.", ["The correction panel becomes available only after all required items are Received or Verified."])
    add_step(doc, 2, "Confirm the correction with the applicant.", ["Ask the applicant, parent, or authorized representative.", "Record the exact confirmed value."])
    add_step(doc, 3, "Enter the reason and check confirmation.", ["Explain why the submitted value was changed.", "Check the confirmation box.", "Select Save Confirmed Corrections."])
    add_warning(doc, "Audit trail", "The system records the before-and-after values and the staff correction reason.")

    add_heading(doc, "4.4 Move the application to the next status", 2)
    add_step(doc, 1, "Choose Move Application.", ["Select the next status displayed by the system.", "Choose Close only when the application will not proceed."])
    add_step(doc, 2, "Enter a close reason when closing.", ["Choose a listed reason or select Other.", "Describe Other briefly and accurately."])
    add_step(doc, 3, "Save the status.", ["Confirm the status badge changed.", "Use the next steps shown by the page; do not bypass document or profile requirements."])

    add_heading(doc, "4.5 Schedule a non-Marriage sacrament", 2)
    add_step(doc, 1, "Approve the application first.", ["Scheduling buttons require an Approved or Scheduled state."])
    add_step(doc, 2, "Select a date.", ["Use the schedule picker to view existing parish and funeral schedules.", "Choose an available 60-minute slot."])
    add_step(doc, 3, "Save the confirmed schedule.", ["The schedule becomes visible in the application and calendar.", "The applicant may receive the existing schedule notification."])

    add_heading(doc, "4.6 Schedule a Marriage application", 2)
    add_task_intro(doc, "Create a tentative proposal, obtain both applicants' agreement, and confirm the booking.", "Requests > Sacrament Pre-Applications > Marriage application.")
    add_step(doc, 1, "Save a tentative selection.", ["The preferred date and time are used as the starting selection.", "Saving a draft does not reserve or confirm the slot."])
    add_step(doc, 2, "Send the proposal.", ["Wait until approval and all Marriage requirements are complete.", "Use Email Proposal or Record Manual Sending."])
    add_step(doc, 3, "Record both parties' agreement.", ["Select the confirmation method.", "Check the box confirming both applicants agreed.", "Save the agreement."])
    add_step(doc, 4, "Confirm and book.", ["The final confirmation rechecks availability.", "If the slot remains open, select Confirm and Book.", "A changed or conflicted slot requires a new proposal and fresh agreement."])
    add_warning(doc, "No reservation", "A draft or sent proposal does not reserve the church calendar.")

    add_heading(doc, "4.7 Complete after the ceremony", 2)
    add_step(doc, 1, "Open the prefilled register form.", ["Use Open Prefilled Register Form after the application is Approved or Scheduled.", "Link both Marriage applicants before opening the Marriage form."])
    add_step(doc, 2, "Check and save the official record.", ["Compare against the physical register.", "Enter the book, page, line, minister, place, date, and sponsor details that apply."])
    add_step(doc, 3, "Save the sacramental record.", ["Saving completes the application when the system has been configured to link the register record.", "Review the application status and audit history."])

    add_heading(doc, "5. Use the Age Eligibility Review", 1)
    add_task_intro(doc, "Review active applications or parishioners for age-related preparation reminders.", "People & Records > Age Eligibility Review.")
    add_step(doc, 1, "Open the Age Eligibility Review.", ["Choose First Communion, Confirmation, or Marriage."])
    add_step(doc, 2, "For Marriage, review both parties.", ["The page compares the groom and bride birth dates with the reference date.", "Confirmed schedule date is used when one exists.", "Today's date is used only when no confirmed schedule exists."])
    add_step(doc, 3, "Review consent or advice indicators.", ["Use the result as a reminder only.", "Confirm actual canonical or civil requirements with the parish priest and supporting records."])
    add_warning(doc, "Advisory only", "Age screening does not approve or disapprove an application.")

    add_heading(doc, "6. Process a Certificate Request", 1)
    add_task_intro(doc, "Match a certificate to an official register record and complete pickup.", "Requests > Certificate Requests.")
    add_step(doc, 1, "Open the request.", ["Review the sacrament, subject name, requester relationship, purpose, and status."])
    add_step(doc, 2, "Find the matching sacramental record.", ["Search by name and birth or marriage information.", "Compare the official register index before selecting a record."])
    add_step(doc, 3, "Mark payment if received.", ["Payment is recorded by staff.", "No payment amount is stored."])
    add_step(doc, 4, "Print or prepare the certificate.", ["Verify the printed details against the register.", "Enter the certificate number if required."])
    add_step(doc, 5, "Await the priest signature.", ["Move the request to the signature/ready stage only after the physical process is complete."])
    add_step(doc, 6, "Release the certificate.", ["Record the claimant.", "Confirm a valid ID for the named person or authorization documents for a representative.", "Save the release/pickup action."])

    add_heading(doc, "7. Enter a Sacramental Record", 1)
    add_task_intro(doc, "Encode or correct an official register entry.", "People & Records > Records.")
    add_step(doc, 1, "Open the correct record type.", ["Choose Baptism, First Communion, Confirmation, or Marriage."])
    add_step(doc, 2, "Link or search for the person.", ["Use the master person index.", "For Marriage, select groom and bride profiles."])
    add_step(doc, 3, "Copy the physical register entry.", ["Enter event date, place, minister, book, page, line, and sacrament details.", "Do not interpret uncertain handwritten text without the parish register comparison."])
    add_step(doc, 4, "Save the record.", ["Correct errors only after staff verification.", "Enter a correction reason when the form requests one."])
    add_warning(doc, "Official source", "The physical register or approved parish source is authoritative.")

    add_heading(doc, "8. Use the Calendar", 1)
    add_task_intro(doc, "View or add parish schedules.", "Schedule > Calendar.")
    add_step(doc, 1, "Open the calendar.", ["Review month, week, or list view."])
    add_step(doc, 2, "Select or create an event.", ["Choose the event type and date.", "Enter title, start time, end time, public details, and private notes as needed."])
    add_step(doc, 3, "Check conflicts.", ["Review existing parish and sacrament events.", "Resolve conflicts before confirming."])

    add_heading(doc, "9. Manage Parish Announcements", 1)
    add_task_intro(doc, "Publish or hide public notices.", "Schedule > Announcements.")
    add_step(doc, 1, "Create a notice.", ["Enter title, message, event date if relevant, and publication state."])
    add_step(doc, 2, "Review the public wording.", ["Remove internal staff notes or sensitive information."])
    add_step(doc, 3, "Save and verify.", ["Open the public site and confirm the announcement appears only when it should."])

    add_heading(doc, "10. Use Reports", 1)
    add_task_intro(doc, "Review monthly or yearly parish workload.", "Top navigation > Reports.")
    add_step(doc, 1, "Choose Monthly or Yearly.", ["Select the year and month if applicable."])
    add_step(doc, 2, "Review totals.", ["Check applications, certificate requests, sacraments, schedules, and other displayed metrics."])
    add_step(doc, 3, "Export or print only for an approved use.", ["Protect any report that contains personal information."])

    add_heading(doc, "11. Create a Protected Backup", 1)
    add_task_intro(doc, "Download an encrypted database copy.", "System > Backup.")
    add_step(doc, 1, "Re-enter the staff password.", ["This verifies that the staff member is authorized."])
    add_step(doc, 2, "Enter a backup passphrase.", ["Use at least 12 characters.", "Do not use the staff password."])
    add_step(doc, 3, "Download the backup.", ["Save the `.sql.enc` file in approved parish storage.", "Record the filename and date."])
    add_step(doc, 4, "Store the passphrase separately.", ["Use an approved password manager or sealed record.", "Do not send the passphrase with the backup file."])
    add_warning(doc, "Never reset the live database", "Restore operations require a protected technical procedure and a backup of the current database.")

    add_heading(doc, "12. Use Audit and Account Tools", 1)
    add_heading(doc, "12.1 Review audit history", 2)
    add_step(doc, 1, "Open System > Audit Log.", ["Search by staff, action, module, date, or reference."])
    add_step(doc, 2, "Open correction details.", ["Use the changed-values table to review before-and-after information.", "Export only when authorized."])
    add_heading(doc, "12.2 Manage a staff account", 2)
    add_step(doc, 1, "Open System > Accounts.", ["Create or deactivate accounts for authorized staff."])
    add_step(doc, 2, "Use individual accounts.", ["Do not share one login.", "Deactivate an account instead of sharing or duplicating it."])

    add_heading(doc, "13. Quick Problem Guide", 1)
    add_heading(doc, "13.1 500 Server Error", 2)
    add_step(doc, 1, "Stop and record the page.", ["Write down the URL and exact action attempted."])
    add_step(doc, 2, "Do not import or change files repeatedly.", ["Ask the system administrator to inspect PHP and database error logs."])

    add_heading(doc, "13.2 Person profile not found", 2)
    add_step(doc, 1, "Try alternate spelling.", ["Search surname, first name, or birth date.", "Check parent names and existing register references."])
    add_step(doc, 2, "Create only if necessary.", ["Create a profile only after identity matching has been completed."])

    add_heading(doc, "13.3 Calendar slot unavailable", 2)
    add_step(doc, 1, "Choose another date or time.", ["Check existing parish and burial schedules.", "Recheck at the final confirmation step."])
    add_step(doc, 2, "For Marriage, send a new proposal.", ["A conflict requires a new tentative selection and fresh applicant agreement."])

    add_heading(doc, "13.4 Email not received", 2)
    add_step(doc, 1, "Confirm the email address.", ["Ask the applicant or representative to verify it."])
    add_step(doc, 2, "Check the email log.", ["Open System > Email Log if available.", "Contact the applicant by an approved alternative when delivery is uncertain."])

    add_heading(doc, "Appendix A. Quick Reference", 1)
    table = doc.add_table(rows=1, cols=3)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.style = "Table Grid"
    headers = ["Task", "Menu", "Main result"]
    for i, header in enumerate(headers):
        table.cell(0, i).text = header
        set_cell_shading(table.cell(0, i), "1F5E3A")
        for run in table.cell(0, i).paragraphs[0].runs:
            run.bold = True
            run.font.color.rgb = RGBColor(255, 255, 255)
    set_repeat_table_header(table.rows[0])
    rows = [
        ("Submit a pre-application", "Public sacrament form", "Reference number issued"),
        ("Search a person", "People & Records", "Person profile located"),
        ("Review an application", "Requests > Sacrament Pre-Applications", "Status and requirements updated"),
        ("Review marriage age", "People & Records > Age Eligibility Review", "Groom and bride advisory ages shown"),
        ("Process a certificate", "Requests > Certificate Requests", "Certificate matched and released"),
        ("Open register record", "People & Records > Records", "Sacramental entry maintained"),
        ("Schedule an event", "Schedule > Calendar", "Calendar booking checked"),
        ("Create a backup", "System > Backup", "Encrypted SQL export downloaded"),
    ]
    for values in rows:
        row = table.add_row()
        for i, value in enumerate(values):
            row.cells[i].text = value

    section = doc.sections[-1]
    footer = section.footer
    p = footer.paragraphs[0]
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.add_run("SRS-SPPO — Step-by-Step User Manual v2.0")

    doc.core_properties.title = "SRS-SPPO Step-by-Step User Manual"
    doc.core_properties.subject = "Public portal and secretary operating instructions"
    doc.core_properties.author = "St. Patrick's Parish Office"

    doc.save(OUTPUT)
    print(OUTPUT)


if __name__ == "__main__":
    main()
