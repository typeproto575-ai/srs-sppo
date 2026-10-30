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
    <p>Check what to have ready before you start. The parish office will tell you if your situation needs anything else.</p>
</section>

<section class="option-group" aria-labelledby="important-first-heading">
    <h2 id="important-first-heading">Important before using an online form</h2>
    <div class="hub-cards">
        <div class="hub-card">
            <h3>No document uploads</h3>
            <p>Do not upload or send personal documents with the form. If a document is needed, the parish office will tell you how to show it in person. You may contact the office with questions.</p>
        </div>
        <div class="hub-card">
            <h3>Submission is not approval</h3>
            <p>Sending a form does not mean it is approved. The parish office checks the details and confirms the next steps.</p>
        </div>
        <div class="hub-card">
            <h3>Complete the process onsite</h3>
            <p>Certificates are collected at the parish office. They are not issued or downloaded from this website.</p>
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
                    <li>Child's full name, date of birth, and birthplace.</li>
                    <li>Parent or guardian's name and contact details.</li>
                    <li>Parents' names, if known.</li>
                    <li>Godfathers' and godmothers' names, if known. The parish office will explain any requirements for sponsors.</li>
                    <li>A preferred baptism date, if you have one. The parish office confirms the schedule.</li>
                </ul>
                <p class="field-hint"><strong>After submission:</strong> the office will explain any documents or preparation needed for your family.</p>
                <a href="<?= e($basePath) ?>/public/sacrament-applications/baptismal.php" class="btn btn-small">Start Baptism application</a>
            </div>
        </div>

        <div class="ledger-row">
            <span class="ledger-no"></span>
            <div>
                <h3>Marriage application</h3>
                <ul class="req-list">
                    <li>Both parties' names, birth details, contact details, and current marital status. Addresses and religious affiliation can be provided if known.</li>
                    <li>Each party's baptism and Confirmation status, and any previous marriage.</li>
                    <li>Preferred wedding date, time, and venue.</li>
                    <li>Parents' names, if known, and any civil-marriage details that apply.</li>
                    <li>Principal sponsors' names, if known.</li>
                    <li>Baptismal and Confirmation certificates, a PSA civil-status document (such as a CENOMAR), or marriage-license documents if requested by the parish.</li>
                </ul>
                <p class="field-hint"><strong>After submission:</strong> the parish office will explain document requirements, preparation, interviews, and scheduling. A wedding date is not confirmed by this form.</p>
                <a href="<?= e($basePath) ?>/public/sacrament-applications/marriage.php" class="btn btn-small">Start Marriage application</a>
            </div>
        </div>
        <div class="ledger-row">
            <span class="ledger-no"></span>
            <div>
                <h3>Confirmation application</h3>
                <ul class="req-list">
                    <li>Applicant's full name and birthdate.</li>
                    <li>Parent or guardian's name and contact details.</li>
                    <li>Whether the applicant was baptized at this parish, another parish or church, or you are not sure.</li>
                </ul>
                <p class="field-hint">The parish office will explain any documents, preparation, and schedule after reviewing the application.</p>
                <a href="<?= e($basePath) ?>/public/sacrament-applications/confirmation.php" class="btn btn-small">Start Confirmation application</a>
            </div>
        </div>
        <div class="ledger-row">
            <span class="ledger-no"></span>
            <div>
                <h3>First Communion application</h3>
                <ul class="req-list">
                    <li>Applicant's full name and date of birth.</li>
                    <li>Parent or guardian's name and contact details. Parents' names are optional.</li>
                    <li>Whether the applicant was baptized at this parish, another parish or church, or you are not sure.</li>
                </ul>
                <p class="field-hint">The parish office will explain any documents, preparation, and schedule after reviewing the application.</p>
                <a href="<?= e($basePath) ?>/public/sacrament-applications/first-communion.php" class="btn btn-small">Start First Communion application</a>
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
                    <li>The name and birthdate or sacrament date as recorded in the parish register.</li>
                    <li>For Baptism, the father's name and mother's maiden name. For Marriage, both spouses' names and the marriage date.</li>
                    <li>Parent names may also be requested to help find a Baptism, Confirmation, or First Communion record.</li>
                    <li>Your name and relationship to the person, if you are requesting for someone else.</li>
                    <li>Your email address and contact number.</li>
                </ul>
                <p class="field-hint">If the record is not found online, you can still ask the secretary to search the parish register. Bring identification when collecting the certificate.</p>
                <a href="<?= e($basePath) ?>/public/certificate-request.php" class="btn btn-small">Request a Certificate</a>
            </div>
        </div>
    </div>
</section>

<section class="option-group" aria-labelledby="what-to-bring-heading">
    <h2 id="what-to-bring-heading">When you visit the office</h2>
    <ul class="req-list">
        <li>Bring only the documents the parish office asks you to provide.</li>
        <li>Quote your reference number (shown on your submission confirmation) when contacting the office.</li>
    </ul>
    <p class="field-hint">Document requirements and schedules may change. The parish secretary's confirmation is the final guide for your case.</p>
</section>

<section class="option-group requirements-contact" aria-labelledby="requirements-contact-heading">
    <h2 id="requirements-contact-heading">Not sure what applies?</h2>
    <p>Contact the parish office if you are unsure which form to use or what documents to prepare.</p>
    <div class="hero-actions">
        <a href="https://www.facebook.com/profile.php?id=61575322479373" class="btn" target="_blank" rel="noopener noreferrer">Visit our Facebook Page</a>
        <a href="<?= e($basePath) ?>/public/how-it-works.php" class="btn btn-secondary">How It Works</a>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
