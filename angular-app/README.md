# Parish Services Angular frontend

This is a standalone Angular frontend in its own folder. It uses the existing public-site and
staff styles. The Angular Office Services page retains the current service choices and links; the
staff workspace adds an Angular dashboard backed by read-only PHP/MySQL APIs. Existing PHP forms,
validation, request tracking, and staff workflows remain available during the incremental migration.

The Angular public interface currently includes Office Services, How It Works, Requirements,
Privacy Notice, and Terms and Conditions.
It preserves the existing shared header, navigation, footer, and styles:

1. Choose one of four sacrament pre-applications or request a certificate copy.
2. Read the parish review, preparation, document-handling, and in-person pickup guidance.
3. Continue to the existing PHP forms and verified request tracking for submission and status lookup.

An application does not create a register entry or a certificate request. Visitors can track their
requests through the existing email-code verification flow. The app does not collect document
uploads, ID images, or payments.

## Run locally with XAMPP

Start Apache and MySQL, and make sure the PHP project is available at
`http://localhost/srs-sppo`. From this folder, run:

```text
npm ci
npm start
```

Open `http://localhost:4200` for Office Services. Angular public routes also include
`/public/how-it-works`, `/public/requirements`, `/public/privacy`, and `/public/terms`. The
development proxy in `proxy.conf.json` forwards parish links and staff API calls through to XAMPP.

To open the Angular staff dashboard, first sign in through the existing PHP staff login at
`http://localhost/srs-sppo/admin/login.php`, then open
`http://localhost:4200/admin/dashboard`. Keep the same hostname (`localhost`) for both so the
browser sends the PHP session cookie to the proxied API.

The Angular dashboard currently provides the request overview and master-person search. The
application and certificate forms, request tracker, public home/calendar, and other secretary
pages continue to use their existing PHP screens while they are migrated.

## Build for deployment

```text
npm run build
```

The static Angular build is written to `dist/angular-app/browser`. Serve that directory from the
same host as the PHP app, with SPA fallback to `index.html` for Angular routes. If it is deployed
under a subdirectory, build with the matching base URL, for example
`npm run build -- --base-href /srs-sppo/angular-app/`. If the PHP project is installed at a
different path, update the `/srs-sppo` backend paths in `src/app/` and
`src/main.ts`; for local development, also update the target in `proxy.conf.json`.
