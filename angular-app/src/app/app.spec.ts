import { TestBed } from '@angular/core/testing';
import { ServicesPageComponent } from './services-page';

describe('App', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ServicesPageComponent],
    }).compileComponents();
  });

  it('preserves the parish services and certificate links', () => {
    const fixture = TestBed.createComponent(ServicesPageComponent);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    const applicationLinks = Array.from(compiled.querySelectorAll<HTMLAnchorElement>('.service-card'))
      .slice(0, 4)
      .map((link) => link.getAttribute('href'));

    expect(applicationLinks).toEqual([
      '/srs-sppo/public/sacrament-applications/baptismal.php',
      '/srs-sppo/public/sacrament-applications/first-communion.php',
      '/srs-sppo/public/sacrament-applications/confirmation.php',
      '/srs-sppo/public/sacrament-applications/marriage.php',
    ]);
    expect(
      compiled.querySelector<HTMLAnchorElement>('#certificate-requests .service-card')?.getAttribute('href'),
    ).toBe('/srs-sppo/public/certificate-request.php');
    expect(compiled.querySelector('.workflow-hero h1')?.textContent).toContain('What do you need?');
    expect(compiled.querySelector<HTMLAnchorElement>('.footer-links a')?.getAttribute('href'))
      .toBe('/public/terms');
    expect(compiled.querySelectorAll<HTMLAnchorElement>('.footer-links a')[1]?.getAttribute('href'))
      .toBe('/public/privacy');
  });

  it('opens and closes the mobile navigation accessibly', () => {
    const fixture = TestBed.createComponent(ServicesPageComponent);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    const toggle = compiled.querySelector<HTMLButtonElement>('.nav-toggle');
    expect(toggle?.getAttribute('aria-expanded')).toBe('false');

    toggle?.click();
    fixture.detectChanges();

    expect(toggle?.getAttribute('aria-expanded')).toBe('true');
    expect(compiled.querySelector('#site-nav')?.classList.contains('open')).toBeTrue();

    fixture.componentInstance.closeNav();
    fixture.detectChanges();

    expect(toggle?.getAttribute('aria-expanded')).toBe('false');
    expect(compiled.querySelector('#site-nav')?.classList.contains('open')).toBeFalse();
  });
});
