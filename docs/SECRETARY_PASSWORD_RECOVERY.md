# Secretary Password Recovery

The staff login now includes a password-recovery flow restricted to active `secretary` accounts.

The request response does not disclose whether an email matches an account. Matching requests create a cryptographically random one-time token; only its SHA-256 hash is stored, and the raw token is sent through the existing mail helper. Tokens expire after 30 minutes and are consumed once. Resetting a password increments `users.auth_version`, invalidating existing sessions.

## Deployment

Apply `migrations/024_secretary_password_recovery.sql` after the existing Phase 5A migration. Email delivery remains disabled until the official parish mail relay is configured through the deployment environment.

## Acceptance checks

1. Apply the migration on an isolated database and verify `users.auth_version` and `password_reset_tokens`.
2. Open the staff login and confirm the recovery link is present.
3. Submit both unknown and known staff email addresses and confirm the public response is identical.
4. With mail disabled, confirm the request is logged as disabled and no reset link is displayed in the browser.
5. With the staging relay enabled, confirm the one-time link expires after 30 minutes.
6. Reset a password with at least 12 characters, reuse the link, and verify the reuse is rejected.
7. Confirm existing sessions are invalidated after a successful reset.
8. Confirm reset requests are rate-limited and inactive accounts cannot receive or complete a reset.
