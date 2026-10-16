<?php
/**
 * =====================================================================
 * SRS-SPPO TERMS AND CONDITIONS (public legal page)
 * =====================================================================
 * Rendered as a formal numbered-clause document (see .legal-document in
 * assets/css/style.css), distinct from the site's card-style pages. The
 * content is unchanged in substance from the earlier version -- only the
 * structure and presentation were reworked. Referenced by:
 *   - Every public request/application form's Terms checkbox
 *   - #personal-data: linked from the record-search "How we use your
 *     data" line on the four certificate-request pages
 * NOTE: The Data Privacy Act reference below is descriptive context, not
 * a certified compliance statement -- have this reviewed before relying
 * on it as an actual legal document.
 * =====================================================================
 */
require_once __DIR__ . '/../includes/functions.php';
$basePath = $basePath ?? base_path();
$pageTitle = 'Terms and Conditions';
require_once __DIR__ . '/../includes/header.php';
?>

<article class="legal-document">
    <header class="legal-document__header">
        <span class="legal-document__kicker">St. Patrick's Parish Office &middot; Dingalan, Aurora</span>
        <h1>Terms and Conditions</h1>
        <p class="legal-document__subtitle">Governing the use of this website's online certificate-request and sacrament-application service.</p>
        <div class="legal-document__meta">
            <span>Effective: September 20, 2026</span>
            <span>Last Updated: September 20, 2026</span>
        </div>
    </header>

    <div class="legal-document__body">

        <section class="legal-clause">
            <h2 class="legal-clause__title">Nature of an Online Submission</h2>
            <p>A submission made through this website is a <span class="legal-term">pre-application</span> for review by the Parish Office. It does not constitute approval, an official parish certificate, or a guarantee that a corresponding record will be located or released. All submissions remain subject to verification and final disposition by parish personnel.</p>
        </section>

        <section class="legal-clause">
            <h2 class="legal-clause__title">Accuracy of Information</h2>
            <p>By submitting a form on this website, the requesting party represents that the information provided is accurate and complete to the best of their knowledge. The Parish Office reserves the right to request clarification, additional information, or supporting documentation before a submission is processed.</p>
        </section>

        <section class="legal-clause">
            <h2 class="legal-clause__title">Record Verification and Release</h2>
            <ol class="legal-subclauses">
                <li>The Parish Office verifies the identity of the requesting party and the details of the record concerned prior to the release of any certificate.</li>
                <li>Certificates are released <span class="legal-term">in person only</span>, at the Parish Office, upon presentation of valid identification and compliance with any further instruction issued by the parish secretary.</li>
                <li>Nothing in an online submission entitles the requesting party to remote or electronic release of a certificate.</li>
            </ol>
        </section>

        <section class="legal-clause" id="personal-data">
            <h2 class="legal-clause__title">Use of Personal Data</h2>
            <ol class="legal-subclauses">
                <li>Contact details supplied through this website are used to process the submission, issue a tracking number, communicate status updates, request clarification, and coordinate pickup.</li>
                <li>Where a submission includes a record search, the name, birthdate, and parents' names entered are used solely to locate the corresponding entry in the parish register. The volume of searches originating from a given network is monitored to prevent misuse. A search result discloses no more than the recorded name and sacrament date.</li>
                <li>Personal data collected through this website is handled in a manner consistent with the general principles of the Data Privacy Act of 2012 (Republic Act No. 10173) and is not sold, rented, or disclosed to third parties for marketing purposes.</li>
                <li>For the full notice on what we collect, why, who receives it, how long we keep it, and your rights, see our <a href="<?= e($basePath . '/public/privacy.php') ?>" target="_blank" rel="noopener noreferrer">Privacy Notice</a>.</li>
            </ol>
        </section>

        <section class="legal-clause">
            <h2 class="legal-clause__title">Scope of Service</h2>
            <p>This website does not provide parishioner account registration, online payment processing, identity-document upload, or the online release of certificates. Where documents or payment are required, the Parish Office will advise the requesting party of the applicable procedure directly.</p>
        </section>

        <section class="legal-clause">
            <h2 class="legal-clause__title">Inquiries and Amendment</h2>
            <p>A requesting party who is uncertain as to a requirement, eligibility criterion, record detail, or pickup instruction should contact the Parish Office prior to submission. The Parish Office reserves the right to amend these Terms and Conditions from time to time as its online request process changes; the "Last Updated" date above reflects the most recent revision.</p>
        </section>

    </div>

    <footer class="legal-document__footer">
        <span class="legal-document__seal">&#10003; St. Patrick's Parish Office</span>
    </footer>
</article>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
