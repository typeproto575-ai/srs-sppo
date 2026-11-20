import { Component } from '@angular/core';
import { PublicPageShellComponent } from './public-page-shell.component';

@Component({
  selector: 'app-requirements-page',
  imports: [PublicPageShellComponent],
  template: `
    <app-public-page-shell>
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
        <h2 id="application-preparation-heading">Starting a pre-application to receive a sacrament</h2>
        <p>These pre-applications are for a future Baptism, Confirmation, First Communion, or Church Wedding. Prepare the details below before starting the online form. Do not send sensitive documents through this page.</p>
        <div class="ledger">
          @for (guide of applicationGuides; track guide.title) {
            <div class="ledger-row">
              <span class="ledger-no"></span>
              <div>
                <h3>{{ guide.title }}</h3>
                <ul class="req-list">
                  @for (item of guide.items; track item) {
                    <li>{{ item }}</li>
                  }
                </ul>
                <p class="field-hint">{{ guide.note }}</p>
                <a [href]="guide.href" class="btn btn-small">{{ guide.action }}</a>
              </div>
            </div>
          }
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
                @for (item of certificateDetails; track item) {
                  <li>{{ item }}</li>
                }
              </ul>
              <p class="field-hint">If the record is not found online, you can still ask the secretary to search the parish register. Bring your person reference and any claim stub or payment slip the secretary gave you. If you are collecting a request made for yourself, bring a valid ID with a name matching the person on the certificate. The Acknowledgment Receipt is placed with the completed certificate in its folder. If someone else collects it, they must bring a signed authorization letter from the person named on the certificate.</p>
              <a href="/srs-sppo/public/certificate-request.php" class="btn btn-small">Request a Certificate</a>
            </div>
          </div>
        </div>
      </section>

      <section class="option-group" aria-labelledby="what-to-bring-heading">
        <h2 id="what-to-bring-heading">When you visit the office</h2>
        <ul class="req-list">
          <li>Bring only the documents the parish office asks you to provide.</li>
          <li>When contacting the Parish Office about your request, quote the person reference shown on your submission confirmation.</li>
        </ul>
        <p class="field-hint">Document requirements and schedules may change. The parish secretary's confirmation is the final guide for your case.</p>
      </section>

      <section class="option-group requirements-contact" aria-labelledby="requirements-contact-heading">
        <h2 id="requirements-contact-heading">Not sure what applies?</h2>
        <p>Contact the parish office if you are unsure which form to use or what documents to prepare.</p>
        <div class="hero-actions">
          <a href="https://www.facebook.com/profile.php?id=61575322479373" class="btn" target="_blank" rel="noopener noreferrer">Visit our Facebook Page</a>
          <a href="/public/how-it-works" class="btn btn-secondary">How It Works</a>
        </div>
      </section>
    </app-public-page-shell>
  `,
})
export class RequirementsPageComponent {
  readonly applicationGuides = [
    {
      title: 'Baptism pre-application',
      items: [
        "Child's full name, date of birth, and birthplace.",
        "Parent or guardian's name and email address for updates. A phone number is optional for quicker contact.",
        "Parents' names, if known.",
        "Godfathers' and godmothers' names, if known. The parish office will explain any requirements for sponsors.",
        'A preferred baptism date, if you have one. The parish office confirms the schedule.',
      ],
      note: 'After submission: the office will explain any documents or preparation needed for your family.',
      href: '/srs-sppo/public/sacrament-applications/baptismal.php',
      action: 'Start Baptism pre-application',
    },
    {
      title: 'Marriage pre-application',
      items: [
        "Both parties' names, birth details, email addresses for updates, and current marital status. Phone numbers are optional for quicker contact. Addresses and religious affiliation can be provided if known.",
        "Each party's baptism and Confirmation status, and any previous marriage.",
        'Preferred wedding date, time, and venue.',
        "Parents' names, if known, and any civil-marriage details that apply.",
        "Principal sponsors' names, if known.",
        'Baptismal and Confirmation certificates, a PSA civil-status document (such as a CENOMAR), or marriage-license documents if requested by the parish.',
      ],
      note: 'After submission: the parish office will explain document requirements, preparation, interviews, and scheduling. A wedding date is not confirmed by this form.',
      href: '/srs-sppo/public/sacrament-applications/marriage.php',
      action: 'Start Marriage pre-application',
    },
    {
      title: 'Confirmation pre-application',
      items: [
        "Applicant's full name and birthdate.",
        "Parent or guardian's name and email address for updates. A phone number is optional for quicker contact.",
        'Whether the applicant was baptized at this parish, another parish or church, or you are not sure.',
      ],
      note: 'The parish office will explain any documents, preparation, and schedule after reviewing the pre-application.',
      href: '/srs-sppo/public/sacrament-applications/confirmation.php',
      action: 'Start Confirmation pre-application',
    },
    {
      title: 'First Communion pre-application',
      items: [
        "Applicant's full name and date of birth.",
        "Parent or guardian's name and email address for updates. A phone number is optional for quicker contact. Parents' names are optional.",
        'Whether the applicant was baptized at this parish, another parish or church, or you are not sure.',
      ],
      note: 'The parish office will explain any documents, preparation, and schedule after reviewing the pre-application.',
      href: '/srs-sppo/public/sacrament-applications/first-communion.php',
      action: 'Start First Communion pre-application',
    },
  ];

  readonly certificateDetails = [
    'The name and birthdate or sacrament date as recorded in the parish register.',
    "For Baptism, the father's name and mother's maiden name. For Marriage, both spouses' names and the marriage date.",
    'Parent names may also be requested to help find a Baptism, Confirmation, or First Communion record.',
    'Your name and relationship to the person, if you are requesting for someone else.',
    'Your email address for updates. A contact number is optional for quicker contact.',
  ];
}
