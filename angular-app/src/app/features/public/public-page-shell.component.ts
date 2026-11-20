import { Component, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-public-page-shell',
  imports: [RouterLink],
  template: `
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <header class="site-header">
      <a href="/srs-sppo/public/index.php" class="site-brand">
        <img src="/srs-sppo/assets/images/logo.jpg" alt="St. Patrick's Parish logo" class="brand-logo-public">
        St. Patrick's Parish Office
      </a>
      <button
        type="button"
        class="nav-toggle"
        [attr.aria-expanded]="isNavOpen()"
        aria-controls="site-nav"
        (click)="toggleNav()"
      >
        <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
          <path d="M4 6h16M4 12h16M4 18h16"></path>
        </svg>
        Menu
      </button>
      <nav id="site-nav" [class.open]="isNavOpen()" aria-label="Main navigation">
        <a href="/srs-sppo/public/index.php" (click)="closeNav()">Home</a>
        <a routerLink="/public/how-it-works" (click)="closeNav()">How It Works</a>
        <a href="/srs-sppo/public/track-request.php" (click)="closeNav()">View My Requests</a>
        <a routerLink="/public/requirements" (click)="closeNav()">Requirements</a>
        <a routerLink="/public/services" (click)="closeNav()">Office Services</a>
      </nav>
    </header>
    <main class="site-main public-page" id="main-content">
      <ng-content></ng-content>
    </main>
    <footer class="site-footer">
      <p class="footer-links">
        <a routerLink="/public/terms">Terms and Conditions</a>
        &middot;
        <a routerLink="/public/privacy">Privacy Notice</a>
      </p>
      <p>&copy; {{ currentYear }} St. Patrick's Parish Office, Dingalan, Aurora.</p>
    </footer>
  `,
})
export class PublicPageShellComponent {
  readonly isNavOpen = signal(false);
  readonly currentYear = new Date().getFullYear();

  toggleNav(): void {
    this.isNavOpen.update((open) => !open);
  }

  closeNav(): void {
    this.isNavOpen.set(false);
  }
}
