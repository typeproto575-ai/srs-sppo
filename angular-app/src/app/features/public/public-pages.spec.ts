import { provideRouter } from '@angular/router';
import { TestBed } from '@angular/core/testing';
import { HowItWorksPageComponent } from './how-it-works-page.component';
import { RequirementsPageComponent } from './requirements-page.component';

describe('Public information pages', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [HowItWorksPageComponent, RequirementsPageComponent],
      providers: [provideRouter([])],
    }).compileComponents();
  });

  it('explains the review and in-person certificate workflow', () => {
    const fixture = TestBed.createComponent(HowItWorksPageComponent);
    fixture.detectChanges();

    const page = fixture.nativeElement as HTMLElement;
    expect(page.querySelector('h1')?.textContent).toContain('careful parish review');
    expect(page.textContent).toContain('Sending a form does not mean it is approved.');
    expect(page.textContent).toContain('Certificates are collected at the Parish Office');
    expect(page.querySelector('a[href="/public/services#sacrament-applications"]')).not.toBeNull();
  });

  it('shows preparation guidance and keeps document handling in person', () => {
    const fixture = TestBed.createComponent(RequirementsPageComponent);
    fixture.detectChanges();

    const page = fixture.nativeElement as HTMLElement;
    expect(page.querySelectorAll('.ledger-row').length).toBe(5);
    expect(page.textContent).toContain('Do not upload or send personal documents');
    expect(page.textContent).toContain('signed authorization letter');
    expect(
      page.querySelector<HTMLAnchorElement>('a[href="/srs-sppo/public/certificate-request.php"]'),
    ).not.toBeNull();
  });
});
