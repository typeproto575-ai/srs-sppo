import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { LegalPageComponent } from './legal-page.component';

describe('Legal pages', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [LegalPageComponent],
      providers: [
        provideRouter([
          {
            path: 'public/privacy',
            component: LegalPageComponent,
            data: { legalPage: 'privacy' },
          },
          {
            path: 'public/terms',
            component: LegalPageComponent,
            data: { legalPage: 'terms' },
          },
        ]),
      ],
    }).compileComponents();
  });

  it('renders the complete draft privacy notice and cross-link', async () => {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/public/privacy', LegalPageComponent);

    const page = harness.routeNativeElement as HTMLElement;
    expect(page.querySelectorAll('.legal-clause').length).toBe(11);
    expect(page.querySelector('h1')?.textContent).toBe('Privacy Notice');
    expect(page.textContent).toContain('[LEGAL NAME OF PARISH / DIOCESAN ENTITY]');
    expect(page.textContent).toContain('The system never receives or stores documents');
    expect(page.querySelector('a[href="/public/terms"]')?.textContent).toBe('Terms and Conditions');
  });

  it('renders the submission and in-person release terms with the privacy link', async () => {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/public/terms', LegalPageComponent);

    const page = harness.routeNativeElement as HTMLElement;
    expect(page.querySelectorAll('.legal-clause').length).toBe(6);
    expect(page.querySelector('h1')?.textContent).toBe('Terms and Conditions');
    expect(page.textContent).toContain('Nothing in an online submission entitles');
    expect(page.querySelector('a[href="/public/privacy"]')?.textContent).toBe('Privacy Notice');
  });
});
