import { Component, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Title } from '@angular/platform-browser';
import { PublicPageShellComponent } from './public-page-shell.component';

type LegalPage = 'privacy' | 'terms';

@Component({
  selector: 'app-legal-page',
  imports: [PublicPageShellComponent, RouterLink],
  template: `
    <app-public-page-shell>
      @if (page === 'privacy') {
        <article class="legal-document">
          <header class="legal-document__header">
            <span class="legal-document__kicker">St. Patrick's Parish Office &middot; Dingalan, Aurora</span>
            <h1>Privacy Notice</h1>
            <p class="legal-document__subtitle">How the Parish Office collects, uses, shares, keeps and protects your personal data, in line with the Data Privacy Act of 2012 (Republic Act No. 10173).</p>
            <div class="legal-document__meta"><span>Draft for demonstration. Not yet in effect.</span></div>
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
                <li><span class="legal-term">Sacrament application details:</span> depending on the form, this may include current address, religion, marital status, previous marriage, baptism and Confirmation status, proposed sponsors, and a student's grade, school year, and requested formation pathway.</li>
                <li><span class="legal-term">Children's data:</span> a child's name, birthdate, parent or guardian details, and information needed for sacrament preparation. A parent or legal guardian must submit the application and provide the required privacy consent. Pastoral formation commitments and readiness are followed up by the Parish Office after review.</li>
                <li><span class="legal-term">Technical data:</span> IP address and the time of requests, kept in an audit log for security and to prevent misuse.</li>
              </ol>
              <p>Religion, age, and civil status are <span class="legal-term">sensitive personal information</span> under Section 3(l) of the Act. We collect them only when needed to review a sacrament application.</p>
              <p>Please give us information about another person (parents, godparents, sponsors, witnesses) only if you are authorized to do so, and let them know you did.</p>
            </section>

            <section class="legal-clause" id="purposes">
              <h2 class="legal-clause__title">Why We Use Your Data</h2>
              <ol class="legal-subclauses">
                <li>To receive an online pre-application or an onsite request/application and issue a reference number.</li>
                <li>To review and verify submitted details against the parish register during staff review. On a sacrament pre-application, entering a valid person reference displays the matching profile's biographical details and a summary of linked sacrament records for reference; other public searches do not disclose whether a register record exists.</li>
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
              <p>We use access controls for staff accounts, password hashing, session timeouts, request rate limits, and an audit log. Keep your person reference private: anyone who enters it on a sacrament pre-application can view the matching profile's biographical details and a summary of linked sacrament records. Certificates are released in person only after the Parish Office verifies the request and recorded payment. A claim stub or payment slip, if issued, helps locate the request but is not required if missing. The Acknowledgment Receipt is placed with the completed certificate in its folder. An authorized representative must also present a signed authorization letter from the person named on the certificate. Supporting documents are shown in person at the Parish Office only. The system never receives or stores documents — please do not email or message identity documents or other personal papers.</p>
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
              <p>We may update this notice. See also our <a routerLink="/public/terms">Terms and Conditions</a>.</p>
            </section>
          </div>

          <footer class="legal-document__footer">
            <span class="legal-document__seal">&#10003; St. Patrick's Parish Office</span>
          </footer>
        </article>
      } @else {
        <article class="legal-document">
          <header class="legal-document__header">
            <span class="legal-document__kicker">St. Patrick's Parish Office &middot; Dingalan, Aurora</span>
            <h1>Terms and Conditions</h1>
            <p class="legal-document__subtitle">Governing the use of this website's online certificate-request and sacrament-application service.</p>
            <div class="legal-document__meta"><span>Draft for demonstration. Not yet in effect.</span></div>
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
                <li>The Parish Office checks the request and record details before releasing any certificate.</li>
                <li>Certificates are released <span class="legal-term">in person only</span>, at the Parish Office, after staff verify the request and recorded payment. A claim stub or payment slip, if issued, can help locate the request but is not required if missing. The Acknowledgment Receipt is placed with the completed certificate in its folder. An authorized representative must present a signed authorization letter from the person named on the certificate.</li>
                <li>Nothing in an online submission entitles the requesting party to remote or electronic release of a certificate.</li>
              </ol>
            </section>

            <section class="legal-clause" id="personal-data">
              <h2 class="legal-clause__title">Use of Personal Data</h2>
              <ol class="legal-subclauses">
                <li>Contact details supplied through this website are used to process the submission, issue a reference number, communicate status updates, request clarification, and coordinate pickup.</li>
                <li>Where a submission includes a record search, the name, birthdate, and parents' names entered are used solely to locate the corresponding entry in the parish register. The volume of searches originating from a given network is monitored to prevent misuse. A search result discloses no more than the recorded name and sacrament date.</li>
                <li>Personal data collected through this website is handled in a manner consistent with the general principles of the Data Privacy Act of 2012 (Republic Act No. 10173) and is not sold, rented, or disclosed to third parties for marketing purposes.</li>
                <li>For the full notice on what we collect, why, who receives it, how long we keep it, and your rights, see our <a routerLink="/public/privacy" target="_blank" rel="noopener noreferrer">Privacy Notice</a>.</li>
              </ol>
            </section>

            <section class="legal-clause">
              <h2 class="legal-clause__title">Scope of Service</h2>
              <p>This website does not provide parishioner account registration, online payment processing, identity-document upload, or the online release of certificates. Where documents or payment are required, the Parish Office will advise the requesting party of the applicable procedure directly.</p>
            </section>

            <section class="legal-clause">
              <h2 class="legal-clause__title">Inquiries and Amendment</h2>
              <p>A requesting party who is uncertain as to a requirement, eligibility criterion, record detail, or pickup instruction should contact the Parish Office prior to submission. The Parish Office reserves the right to amend these Terms and Conditions from time to time as its online request process changes; a revision date will be shown here once these Terms take effect.</p>
            </section>
          </div>

          <footer class="legal-document__footer">
            <span class="legal-document__seal">&#10003; St. Patrick's Parish Office</span>
          </footer>
        </article>
      }
    </app-public-page-shell>
  `,
})
export class LegalPageComponent {
  private readonly route = inject(ActivatedRoute);
  readonly page = this.resolvePage(this.route.snapshot.data['legalPage']);

  constructor() {
    const title = this.page === 'privacy' ? 'Privacy Notice' : 'Terms and Conditions';
    inject(Title).setTitle(`${title} | SRS-SPPO`);
  }

  private resolvePage(value: unknown): LegalPage {
    if (value === 'privacy' || value === 'terms') {
      return value;
    }

    throw new Error('The legal page route must specify a supported legal page.');
  }
}
