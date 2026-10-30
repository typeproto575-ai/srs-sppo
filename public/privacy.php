<?php
/**
 * =====================================================================
 * SRS-SPPO PRIVACY NOTICE (public legal page)
 * =====================================================================
 * Draft privacy notice under RA 10173 (Data Privacy Act of 2012).
 * Linked from the site footer, the Terms page, and every public form.
 *
 * TODO BEFORE GO-LIVE: every [BRACKETED] value is a placeholder the
 * parish must fill in (legal name, DPO, retention periods, contact
 * details). Have a lawyer or the parish's DPO review the final text.
 * This is a draft, not a certified compliance statement.
 * =====================================================================
 */
require_once __DIR__ . '/../includes/functions.php';
$basePath = $basePath ?? base_path();
$pageTitle = 'Privacy Notice';
require_once __DIR__ . '/../includes/header.php';
?>

<article class="legal-document">
    <header class="legal-document__header">
        <span class="legal-document__kicker">St. Patrick's Parish Office &middot; Dingalan, Aurora</span>
        <h1>Privacy Notice</h1>
        <p class="legal-document__subtitle">How the Parish Office collects, uses, shares, keeps and protects your personal data, in line with the Data Privacy Act of 2012 (Republic Act No. 10173).</p>
        <div class="legal-document__meta">
            <span>Effective: [DATE]</span>
            <span>Last Updated: [DATE]</span>
        </div>
    </header>

    <div class="legal-document__body">

        <section class="legal-clause" id="controller">
            <h2 class="legal-clause__title">Who Is Responsible for Your Data</h2>
            <p>The personal information controller is <span class="legal-term">[LEGAL NAME OF PARISH / DIOCESAN ENTITY]</span>, operating St. Patrick's Parish Office, Dingalan, Aurora.</p>
            <p>Data Protection Officer: <span class="legal-term">[DPO NAME]</span> &middot; Email: <span class="legal-term">[DPO EMAIL]</span> &middot; Phone: <span class="legal-term">[DPO PHONE]</span> &middot; Office address: <span class="legal-term">[PARISH OFFICE ADDRESS]</span></p>
        </section>

        <section class="legal-clause" id="contact">
            <h2 class="legal-clause__title">How to Contact Us</h2>
            <p>You can reach the Parish Office in the following order:</p>
            <ol class="legal-subclauses">
                <li><span class="legal-term">Email:</span> <span class="legal-term">[PARISH EMAIL ADDRESS]</span></li>
                <li><span class="legal-term">Contact number:</span> <span class="legal-term">[PARISH CONTACT NUMBER]</span></li>
                <li><span class="legal-term">Facebook / Messenger:</span> our <a href="https://www.facebook.com/profile.php?id=61575322479373" target="_blank" rel="noopener noreferrer">St. Patrick's Parish Facebook Page</a>. Facebook/Messenger is a contact channel for questions only — never send documents or IDs through it. Messages you send through Facebook/Messenger are handled by Meta, a third-party service, so the content you send there is also processed by that platform under its own terms and privacy policy.</li>
            </ol>
        </section>

        <section class="legal-clause" id="data-collected">
            <h2 class="legal-clause__title">What We Collect</h2>
            <ol class="legal-subclauses">
                <li><span class="legal-term">Requester details:</span> name, email address, contact number, and relationship to the person concerned, when applicable. Some onsite requests may also include a mailing address.</li>
                <li><span class="legal-term">Record details:</span> the name, birthdate, birthplace, parents' names and sacrament dates needed for staff to review the request against the parish register.</li>
                <li><span class="legal-term">Sacrament application details:</span> depending on the form, this may include current address, religion, marital status, previous marriage, baptism and Confirmation status, and the names of parents, godparents, or sponsors.</li>
                <li><span class="legal-term">Children's data:</span> a child's name, birthdate, parent or guardian details, and information needed for sacrament preparation. A parent or legal guardian must submit the application and consent for a child.</li>
                <li><span class="legal-term">Technical data:</span> IP address and the time of requests, kept in an audit log for security and to prevent misuse.</li>
            </ol>
            <p>Religion, age, and civil status are <span class="legal-term">sensitive personal information</span> under Section 3(l) of the Act. We collect them only when needed to review a sacrament application.</p>
            <p>Please give us information about another person (parents, godparents, sponsors, witnesses) only if you are authorized to do so, and let them know you did.</p>
        </section>

        <section class="legal-clause" id="purposes">
            <h2 class="legal-clause__title">Why We Use Your Data</h2>
            <ol class="legal-subclauses">
                <li>To receive an online pre-application or an onsite request/application and issue a reference number.</li>
                <li>To review and verify submitted details against the parish register during staff review. Public pages never reveal whether a register record exists.</li>
                <li>To contact you about status, missing information, and pickup.</li>
                <li>To keep parish sacramental records.</li>
                <li>To record payment manually as a status after payment is handled outside this system. The system never takes or processes money.</li>
                <li>To protect the system from abuse, and to keep an audit trail of staff actions.</li>
            </ol>
            <p>We do not sell your data and we do not use it for marketing.</p>
        </section>

        <section class="legal-clause" id="recipients">
            <h2 class="legal-clause__title">Who Receives Your Data</h2>
            <ol class="legal-subclauses">
                <li><span class="legal-term">Parish staff:</span> the parish secretary and the parish administrator, on a need-to-know basis.</li>
                <li><span class="legal-term">Email service (Gmail):</span> status emails are sent through <span class="legal-term">Gmail</span>, so your email address and the message content pass through Google's servers.</li>
                <li><span class="legal-term">Website services:</span> where the site uses an external provider for a specific website feature, your browser may contact that provider directly and the provider may see your IP address.</li>
                <li><span class="legal-term">Diocese or other parishes:</span> <span class="legal-term">[STATE WHETHER RECORDS ARE SHARED, AND WHEN]</span>.</li>
                <li>Government agencies or courts, only when required by law.</li>
            </ol>
            <p>Some service providers may be hosted outside the Philippines. Your data may therefore be transferred across borders only for the limited purposes listed above.</p>
        </section>

        <section class="legal-clause" id="retention">
            <h2 class="legal-clause__title">How Long We Keep It</h2>
            <ol class="legal-subclauses">
                <li>The permanent parish sacramental registers — persons, baptismal records, confirmation records, First Communion records, marriage records, and marriage witnesses — are never purged, anonymized, or flagged as disposable.</li>
                <li>Online requests and applications are anonymized after <strong>60 months</strong> (5 years) from completion or cancellation.</li>
                <li>Audit and security logs (including IP addresses) are retained for <strong>36 months</strong> (3 years); once the period ends, IP addresses and user agents are removed and only the action and date remain.</li>
                <li>Disposable operational logs (rate-limit attempts, email delivery log, notifications, expired or used password reset tokens) are deleted when they become older than <strong>30 days</strong> (rate-limit attempts) or <strong>36 months</strong> (all other listed logs).</li>
                <li>When a retention period ends, the affected data is either deleted or anonymized as described above.</li>
            </ol>
        </section>

        <section class="legal-clause" id="security">
            <h2 class="legal-clause__title">How We Protect It</h2>
            <p>We use access controls for staff accounts, password hashing, session timeouts, request rate limits, and an audit log. Certificates are released in person only, with valid ID. Supporting documents are shown in person at the Parish Office only. The system never receives or stores documents — please do not email or message identity documents or other personal papers.</p>
            <p>If a breach puts your data at risk of serious harm, we will notify you and the National Privacy Commission as the law requires.</p>
        </section>

        <section class="legal-clause" id="rights">
            <h2 class="legal-clause__title">Your Rights</h2>
            <p>Under the Act you may:</p>
            <ol class="legal-subclauses">
                <li>be informed about how your data is processed;</li>
                <li>access the data we hold about you;</li>
                <li>object to the processing, or withdraw your consent;</li>
                <li>ask us to correct inaccurate data (contact the parish office);</li>
                <li>ask us to block or remove data, where the law allows;</li>
                <li>claim compensation for damages from unlawful use of your data; and</li>
                <li>receive your data in a usable format, where applicable.</li>
            </ol>
            <p>The permanent parish sacramental registers are church records, so erasure, purging, anonymization, or flagging as disposable is not available for those records. We will explain any other limit when you ask.</p>
            <p>To use any of these rights, write to the Data Protection Officer at the contact details above.</p>
        </section>

        <section class="legal-clause" id="complaints">
            <h2 class="legal-clause__title">Complaints</h2>
            <p>If you believe your data was mishandled, contact our Data Protection Officer first. You may also file a complaint with the National Privacy Commission (<span class="legal-term">privacy.gov.ph</span>).</p>
        </section>

        <section class="legal-clause" id="consent">
            <h2 class="legal-clause__title">Consent</h2>
            <p>By ticking the privacy consent box on a form, you confirm that you have read this notice and agree to the processing described here. You may withdraw consent at any time by contacting the Data Protection Officer. Withdrawing may mean we can no longer process your request.</p>
        </section>

        <section class="legal-clause" id="changes">
            <h2 class="legal-clause__title">Changes</h2>
            <p>We may update this notice. The "Last Updated" date above shows the latest revision. See also our <a href="<?= e($basePath . '/public/terms.php') ?>">Terms and Conditions</a>.</p>
        </section>

    </div>

    <footer class="legal-document__footer">
        <span class="legal-document__seal">&#10003; St. Patrick's Parish Office</span>
    </footer>
</article>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
