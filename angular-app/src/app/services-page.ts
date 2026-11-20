import { Component, signal } from '@angular/core';

type SacramentType = 'baptism' | 'first_communion' | 'confirmation' | 'marriage';

@Component({
  selector: 'app-services-page',
  templateUrl: './services-page.html',
  styleUrl: './services-page.scss',
})
export class ServicesPageComponent {
  readonly backendBasePath = '/srs-sppo';
  readonly isNavOpen = signal(false);
  readonly currentYear = new Date().getFullYear();

  readonly applicationServices: ReadonlyArray<{
    type: SacramentType;
    number: string;
    label: string;
    description: string;
    linkLabel: string;
  }> = [
    {
      type: 'baptism',
      number: '01',
      label: 'Baptism',
      description: "Apply for a child's Baptism at the parish.",
      linkLabel: 'Start Baptism pre-application',
    },
    {
      type: 'first_communion',
      number: '02',
      label: 'First Holy Communion',
      description: 'Submit a pre-application for First Communion preparation.',
      linkLabel: 'Start First Communion pre-application',
    },
    {
      type: 'confirmation',
      number: '03',
      label: 'Confirmation',
      description: 'Submit a pre-application for Confirmation preparation.',
      linkLabel: 'Start Confirmation pre-application',
    },
    {
      type: 'marriage',
      number: '04',
      label: 'Holy Matrimony',
      description: 'Begin the pre-application and review process for a church wedding.',
      linkLabel: 'Start Marriage pre-application',
    },
  ];

  readonly publicHomeUrl = `${this.backendBasePath}/public/index.php`;
  readonly servicesUrl = '/public/services#sacrament-applications';
  readonly howItWorksUrl = '/public/how-it-works';
  readonly requirementsUrl = '/public/requirements';
  readonly trackerUrl = `${this.backendBasePath}/public/track-request.php`;
  readonly certificateUrl = `${this.backendBasePath}/public/certificate-request.php`;
  readonly privacyUrl = '/public/privacy';
  readonly termsUrl = '/public/terms';

  applicationUrl(type: SacramentType): string {
    const fileByType: Record<SacramentType, string> = {
      baptism: 'baptismal.php',
      first_communion: 'first-communion.php',
      confirmation: 'confirmation.php',
      marriage: 'marriage.php',
    };

    return `${this.backendBasePath}/public/sacrament-applications/${fileByType[type]}`;
  }

  toggleNav(): void {
    this.isNavOpen.update((isOpen) => !isOpen);
  }

  closeNav(): void {
    this.isNavOpen.set(false);
  }
}
