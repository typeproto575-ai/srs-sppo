import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { PublicPageShellComponent } from './public-page-shell.component';

@Component({
  selector: 'app-how-it-works-page',
  imports: [PublicPageShellComponent, RouterLink],
  template: `
    <app-public-page-shell>
      <section class="workflow-hero">
        <span class="eyebrow">How Online Intake Works</span>
        <h1>Convenient submission, careful parish review.</h1>
        <p>Send the details online first. The parish office will review them and tell you what to do next.</p>
      </section>

      <div class="ledger">
        <div class="ledger-row">
          <span class="ledger-no"></span>
          <div>
            <h2>Choose what you need</h2>
            <p>Choose a certificate if the sacrament already happened. Start a pre-application if you are preparing for Baptism, Confirmation, First Communion, or Marriage.</p>
          </div>
        </div>
        <div class="ledger-row">
          <span class="ledger-no"></span>
          <div>
            <h2>Send your request</h2>
            <p>Enter the details requested by the form. You do not need an account. Do not upload or send personal documents with the form.</p>
          </div>
        </div>
        <div class="ledger-row">
          <span class="ledger-no"></span>
          <div>
            <h2>Secretary review</h2>
            <p>The parish office checks the details against its records and confirms any requirements. Sending a form does not mean it is approved.</p>
          </div>
        </div>
        <div class="ledger-row">
          <span class="ledger-no"></span>
          <div>
            <h2>If payment is needed</h2>
            <p>Payment is not taken on this website. If payment is needed, the parish office will tell you the amount and how to pay. Processing continues after the office records payment.</p>
          </div>
        </div>
        <div class="ledger-row">
          <span class="ledger-no"></span>
          <div>
            <h2>Receive updates from the parish office</h2>
            <p>The Parish Office may email you about missing information and pickup. Use your person reference and the email address used on a request to see statuses for linked applications and certificate requests.</p>
          </div>
        </div>
        <div class="ledger-row">
          <span class="ledger-no"></span>
          <div>
            <h2>Finish at the parish office</h2>
            <p>If documents are needed, the parish office will tell you what to bring. Use your existing person reference and contact the office about the same pending application instead of submitting it again. Certificates are collected at the Parish Office; they are not issued online.</p>
          </div>
        </div>
      </div>

      <div class="path-cards">
        <div class="path-card">
          <h2>Sacrament Pre-Application</h2>
          <p>Preparing for Baptism, First Communion, Confirmation, or a church wedding? Start a pre-application for parish-office review.</p>
          <a routerLink="/public/services" fragment="sacrament-applications" class="btn btn-secondary">Start a Pre-Application</a>
        </div>
        <div class="path-card">
          <h2>Certificate Request</h2>
          <p>Need proof of a sacrament that already took place? Request a certified copy.</p>
          <a routerLink="/public/services" fragment="certificate-requests" class="btn btn-secondary">Request a Certificate</a>
        </div>
      </div>

      <div class="workflow-cta">
        <p>After you submit a certificate request or sacrament pre-application, the Parish Office may email you with an update. The same person reference is used for requests linked to your profile; use it with the email address on a request to view linked statuses.</p>
      </div>
    </app-public-page-shell>
  `,
})
export class HowItWorksPageComponent {}
