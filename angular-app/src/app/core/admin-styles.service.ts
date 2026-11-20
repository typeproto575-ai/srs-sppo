import { DOCUMENT } from '@angular/common';
import { inject, Injectable } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class AdminStylesService {
  private readonly document = inject(DOCUMENT);

  load(): () => void {
    const stylesheets = ['admin.css', 'admin-polish.css'].map((file) => {
      const stylesheet = this.document.createElement('link');
      stylesheet.rel = 'stylesheet';
      stylesheet.href = `/srs-sppo/assets/css/${file}`;
      this.document.head.append(stylesheet);
      return stylesheet;
    });

    return () => stylesheets.forEach((stylesheet) => stylesheet.remove());
  }
}
