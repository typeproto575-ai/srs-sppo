import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AdminApiService, DashboardData } from '../../core/admin-api.service';
import { AdminStylesService } from '../../core/admin-styles.service';

@Component({
  selector: 'app-admin-dashboard-page',
  imports: [FormsModule],
  templateUrl: './dashboard-page.html',
  styleUrl: './dashboard-page.scss',
})
export class AdminDashboardPageComponent implements OnInit, OnDestroy {
  private readonly api = inject(AdminApiService);
  private readonly adminStyles = inject(AdminStylesService);
  private removeAdminStyles: (() => void) | null = null;

  readonly data = signal<DashboardData | null>(null);
  readonly fullName = signal('Secretary');
  readonly csrfToken = signal('');
  readonly searchQuery = signal('');
  readonly errorMessage = signal('');
  readonly isLoading = signal(true);
  readonly basePath = '/srs-sppo';
  readonly currentYear = new Date().getFullYear();
  readonly registryTypes = [
    { type: 'baptism', label: 'Baptism' },
    { type: 'confirmation', label: 'Confirmation' },
    { type: 'first_communion', label: 'First Communion' },
    { type: 'marriage', label: 'Marriage' },
  ];

  ngOnInit(): void {
    this.removeAdminStyles = this.adminStyles.load();
    this.api.session().subscribe({
      next: (session) => {
        this.fullName.set(session.user?.full_name || 'Secretary');
        this.csrfToken.set(session.csrf_token || '');
      },
      error: () => this.errorMessage.set('Your session expired. Please sign in again.'),
    });
    this.loadDashboard();
  }

  ngOnDestroy(): void {
    this.removeAdminStyles?.();
  }

  searchPeople(): void {
    this.loadDashboard(this.searchQuery().trim());
  }

  private loadDashboard(query = ''): void {
    this.isLoading.set(true);
    this.errorMessage.set('');
    this.api.dashboard(query).subscribe({
      next: (data) => {
        this.data.set(data);
        this.isLoading.set(false);
      },
      error: () => {
        this.errorMessage.set('Dashboard information could not be loaded. Please try again.');
        this.isLoading.set(false);
      },
    });
  }
}
