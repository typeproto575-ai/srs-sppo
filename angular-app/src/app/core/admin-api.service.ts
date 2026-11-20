import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';

export interface AdminSession {
  authenticated: boolean;
  csrf_token?: string;
  user?: {
    id: number;
    username: string;
    full_name: string;
    role: string;
  };
}

export interface DashboardData {
  counts: {
    pending_certificates: number;
    new_applications: number;
    ready_for_pickup: number;
    applications_in_progress: number;
  };
  registry_counts: Record<string, number>;
  search_results: Array<{
    id: number;
    full_name: string;
    birth_date: string | null;
    birth_place: string | null;
    has_baptism: boolean;
    has_first_communion: boolean;
    has_confirmation: boolean;
    has_marriage: boolean;
  }>;
}

@Injectable({ providedIn: 'root' })
export class AdminApiService {
  private readonly http = inject(HttpClient);
  private readonly base = '/srs-sppo/admin/api';

  session(): Observable<AdminSession> {
    return this.http.get<AdminSession>(`${this.base}/angular-session.php`, {
      withCredentials: true,
    });
  }

  dashboard(query = ''): Observable<DashboardData> {
    const params = query ? new HttpParams().set('q', query) : new HttpParams();
    return this.http.get<DashboardData>(`${this.base}/angular-dashboard.php`, {
      params,
      withCredentials: true,
    });
  }
}
