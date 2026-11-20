import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';

for (const file of ['style.css', 'polish.css']) {
  const stylesheet = document.createElement('link');
  stylesheet.rel = 'stylesheet';
  stylesheet.href = `/srs-sppo/assets/css/${file}`;
  stylesheet.onerror = () => console.error(`Could not load parish stylesheet: ${stylesheet.href}`);
  document.head.append(stylesheet);
}

bootstrapApplication(App, appConfig)
  .catch((err) => console.error(err));
