import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { AdminStylesService } from '../../core/admin-styles.service';

@Component({
  selector: 'app-admin-login-page',
  styleUrl: './login-page.scss',
  template: `
    <main class="login-page">
      <img
        src="/srs-sppo/assets/images/logo.jpg"
        alt="St. Patrick's Parish logo"
        class="login-logo"
      />
      <h1>Staff Login</h1>
      <p class="field-hint">
        Sign in through the existing secure Parish Office login. Your PHP session continues to
        protect the Angular staff workspace.
      </p>
      <a class="btn" href="/srs-sppo/admin/login.php">Continue to Staff Login</a>
      <p class="login-recovery-link">
        <a href="/srs-sppo/admin/forgot-password.php">Forgot your password?</a>
      </p>
      <p><a href="/srs-sppo/public/index.php">Return to the public site</a></p>
    </main>
  `,
})
export class AdminLoginPageComponent implements OnInit, OnDestroy {
  private readonly adminStyles = inject(AdminStylesService);
  private removeAdminStyles: (() => void) | null = null;

  ngOnInit(): void {
    this.removeAdminStyles = this.adminStyles.load();
  }

  ngOnDestroy(): void {
    this.removeAdminStyles?.();
  }
}
