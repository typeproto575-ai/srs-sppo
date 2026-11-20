import { Routes } from '@angular/router';
import { adminSessionGuard } from './core/admin-session.guard';
import { AdminDashboardPageComponent } from './features/admin/dashboard-page.component';
import { AdminLoginPageComponent } from './features/admin/login-page.component';
import { HowItWorksPageComponent } from './features/public/how-it-works-page.component';
import { LegalPageComponent } from './features/public/legal-page.component';
import { RequirementsPageComponent } from './features/public/requirements-page.component';
import { ServicesPageComponent } from './services-page';

export const routes: Routes = [
  { path: '', pathMatch: 'full', component: ServicesPageComponent },
  { path: 'public/services', component: ServicesPageComponent },
  { path: 'public/how-it-works', component: HowItWorksPageComponent },
  { path: 'public/requirements', component: RequirementsPageComponent },
  { path: 'public/privacy', component: LegalPageComponent, data: { legalPage: 'privacy' } },
  { path: 'public/terms', component: LegalPageComponent, data: { legalPage: 'terms' } },
  { path: 'admin/login', component: AdminLoginPageComponent },
  {
    path: 'admin/dashboard',
    canActivate: [adminSessionGuard],
    component: AdminDashboardPageComponent,
  },
  { path: '**', redirectTo: '' },
];
