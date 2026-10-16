<?php
declare(strict_types=1);

/**
 * Public preparation guide for certificate requests and sacrament applications.
 *
 * The public forms collect the information needed to open a pre-application;
 * they do not accept identity-document uploads or promise approval. The parish
 * office confirms the final document checklist for each person's circumstances.
 */
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Requirements';
$basePath = $basePath ?? base_path();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="workflow-hero">
    <span class="eyebrow">Before You Submit</span>
    <h1>Requirements and preparation guide</h1>
    <p>Use this page to prepare the information and documents the parish office may need. The final checklist depends on the sacrament, the record requested, the parties' circumstances, and the parish schedule. The office will confirm what applies to you.</p>
</section>

<section class="option-group" aria-labelledby="important-first-heading">
    <h2 id="important-first-heading">Important before using an online form</h2>
    <div class="hub-cards">
        <div class="hub-card">
            <h3>No document uploads</h3>
            <p>Online forms do not ask you to upload an ID, certificate, or other personal document. If the parish needs a document, show it in person at the Parish Office (originals or photocopies, as the office requires). The system never receives or stores documents — please do not email or message them.</p>
        </div>
        <div class="hub-card">
            <h3>Submission is not approval</h3>
            <p>An online submission is a pre-application or request for review. The secretary checks the information against the parish records and confirms requirements before processing.</p>
        </div>
        <div class="hub-card">
            <h3>Complete the process onsite</h3>
            <p>Identity is verified in person when required. Certificates are printed and released at the parish office; nothing is issued or downloaded from the public website.</p>
        </div>
    </div>
</section>

<section class="option-group" aria-labelledby="application-preparation-heading">
    <h2 id="application-preparation-heading">If you are applying to receive a sacrament</h2>
    <p>These applications are for a future Baptism, Confirmation, First Communion, or Church Wedding. Prepare the details below before starting the online form. Do not send sensitive documents through this page.</p>

    <div class="ledger">
        <div class="ledger-row">
            <span class="ledger-no"></span>
            <div>
                <h3>Baptism application</h3>
                <ul class="req-list">
                    <li>Child's full name, birthdate, and birthplace.</li>
                    <li>Parent or legal guardian's name, address, contact number, and email address.</li>
                    <li>Parents' names and relevant family or civil-marital information, if applicable.</li>
                    <li>Names and residences of proposed godfathers and godmothers. The parish will explain sponsor eligibility and any limits.</li>
                    <li>Preferred baptism date, if you have one. The parish confirms the actual schedule.</li>
                    <li>Child's birth certificate and any other documents the parish later requests for verification.</li>
                    <li>Parish permission or additional coordination if the family lives outside the parish area, when applicable.</li>
                </ul>
                <p class="field-hint"><strong>After submission:</strong> the office may ask the parent or guardian to attend preparation or a baptismal seminar and to bring documents for in-person review.</p>
                <a href="<?= e($basePath) ?>/public/sacrament-applications/baptismal.php" class="btn btn-small">Open Baptism Application</a>
            </div>
        </div>

        <div class="ledger-row">
            <span class="ledger-no"></span>
            <div>
                <h3>Marriage application</h3>
                <ul class="req-list">
                    <li>Both parties' full names, birth details, addresses, contact numbers, email addresses, religions, occupations, and parents' names.</li>
                    <li>Each party's marital status, previous-marriage information, and whether each is baptized and confirmed Catholic.</li>
                    <li>Target wedding date, preferred time, venue, and any known officiating-priest information.</li>
                    <li>Proposed principal sponsors, including residences if requested.</li>
                    <li>Baptismal and Confirmation certificates for marriage purposes, when applicable. The office confirms the required form, annotations, and validity period.</li>
                    <li>PSA CENOMAR or the civil-status document the office identifies for your situation, marriage-license documents, and any civil-marriage records that apply.</li>
                    <li>Pre-Cana or family-life seminar, canonical interview, and marriage-banns steps arranged with the parish office.</li>
                    <li>Valid identification documents for both parties when requested for in-person verification.</li>
                </ul>
                <p class="field-hint"><strong>Important:</strong> the parish must review the documents and canonical circumstances before a date is approved. The online form only starts that review.</p>
                <a href="<?= e($basePath) ?>/public/sacrament-applications/marriage.php" class="btn btn-small">Open Marriage Application</a>
            </div>
        </div>
        <div class="ledger-row">
            <span class="ledger-no"></span>
            <div>
                <h3>Confirmation application</h3>
                <ul class="req-list">
                    <li>Applicant's full name and birthdate.</li>
                    <li>Parent or guardian's name, contact number, and email address.</li>
                    <li>Whether the applicant was baptized at this parish, at another parish or church, or you are not sure. Bring a copy of the Baptismal Certificate when the parish office asks for it.</li>
                    <li>Sponsor's name, if you have one, and a suggested batch or date. The parish office confirms the actual schedule.</li>
                </ul>
                <p class="field-hint">The parish office will tell you which documents and preparation steps apply.</p>
                <a href="<?= e($basePath) ?>/public/sacrament-applications/confirmation.php" class="btn btn-small">Open Confirmation Application</a>
            </div>
        </div>
        <div class="ledger-row">
            <span class="ledger-no"></span>
            <div>
                <h3>First Communion application</h3>
                <ul class="req-list">
                    <li>Applicant's full name, birthdate, gender, birthplace, and current address.</li>
                    <li>School currently attending, grade level and section, and adviser.</li>
                    <li>Father's full name, mother's full maiden name, and parent or guardian's name, contact number, and email address.</li>
                    <li>Baptism details: where the applicant was baptized, and the baptism date, parish church, and church address if known.</li>
                    <li>Suggested batch or date. The parish office confirms the actual schedule.</li>
                </ul>
                <p class="field-hint">The parish office will tell you which documents and preparation steps apply.</p>
                <a href="<?= e($basePath) ?>/public/sacrament-applications/first-communion.php" class="btn btn-small">Open First Communion Application</a>
            </div>
        </div>
    </div>
</section>

<section class="option-group" aria-labelledby="certificate-preparation-heading">
    <h2 id="certificate-preparation-heading">If you are requesting a certificate copy</h2>
    <p>Use this path when the sacrament already happened and you need a certified copy of an existing parish record. The available certificate requests are Baptism, Confirmation, First Communion, and Marriage.</p>
    <div class="ledger">
        <div class="ledger-row">
            <span class="ledger-no"></span>
            <div>
                <h3>Have these details ready</h3>
                <ul class="req-list">
                    <li>The person's name as recorded, birthdate or sacrament date, and other identifying details requested by the form.</li>
                    <li>For Baptism, the mother's maiden name and the father's name help the office search the register.</li>
                    <li>For Marriage, both spouses' names and the marriage date. If you do not know the exact date, contact the office before submitting because the online form requires a date for the register search.</li>
                    <li>Your name and relationship to the person when requesting for someone else.</li>
                    <li>Your email address, contact number, and the purpose of the certificate.</li>
                    <li>A valid ID to present when the certificate is collected, if requested by the parish office.</li>
                </ul>
                <p class="field-hint">If the record is not found in the online index, you may still submit a request for the secretary to search the physical register.</p>
                <a href="<?= e($basePath) ?>/public/certificate-request.php" class="btn btn-small">Request a Certificate</a>
            </div>
        </div>
    </div>
</section>

<section class="option-group" aria-labelledby="what-to-bring-heading">
    <h2 id="what-to-bring-heading">When you visit the office</h2>
    <ul class="req-list">
        <li>Bring the original documents or clear copies the parish office specifically asks for.</li>
        <li>Bring a valid government-issued ID when staff requests identity verification.</li>
        <li>Bring your tracking number and use the same email address recorded in the request when checking its status.</li>
        <li>Allow time for register matching, document review, interviews, seminars, or schedule confirmation when applicable.</li>
    </ul>
    <p class="field-hint">Document requirements and schedules may change. The parish secretary's confirmation is the final guide for your case.</p>
</section>

<section class="option-group requirements-contact" aria-labelledby="requirements-contact-heading">
    <h2 id="requirements-contact-heading">Not sure what applies?</h2>
    <p>Contact the parish office before submitting if you are unsure about a document, sponsor or godparent eligibility, parish permission, certificate validity, or scheduling.</p>
    <div class="hero-actions">
        <a href="https://www.facebook.com/profile.php?id=61575322479373" class="btn" target="_blank" rel="noopener noreferrer">Visit our Facebook Page</a>
        <a href="<?= e($basePath) ?>/public/how-it-works.php" class="btn btn-secondary">How It Works</a>
        <a href="<?= e($basePath) ?>/public/track-request.php" class="btn btn-secondary">Track a Request</a>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
