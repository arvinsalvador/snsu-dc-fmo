# Production and shared-hosting checklist (Version 1 candidate)

This is a preparation checklist, **not a deployment procedure or evidence of deployment**. The owner must verify the host supports the application's PHP and extension requirements, Composer dependencies, MySQL, a writable private storage area outside the public document root, cron if background jobs are enabled, and HTTPS. Do not upload `.env`, private evidence, logs, or the repository itself into a publicly browsable directory. Point the public web root only to Laravel's `public/` directory.

## Configuration and access

- [ ] Use a separately generated production `APP_KEY`; keep it and all database/API credentials out of source control and backups accessible to the public.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, `APP_TIMEZONE=Asia/Manila`, and an appropriate `LOG_LEVEL`. Verify public 403/404/500 responses reveal no stack traces.
- [ ] Configure production MySQL host/name/user/password with least privilege. Run migrations on a fresh copy first; take a verified backup before applying migrations to existing data.
- [ ] Run only production-safe reference/authorization seeders. Create the first administrator with the interactive `app:create-admin` command; there are no default credentials. Remove/test no demo users.
- [ ] Require HTTPS for web and `/api/v1` bearer tokens. Configure certificate renewal and trusted proxy/host settings for the actual host; do not trust arbitrary forwarded headers or hosts.
- [ ] Set `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax` (adjust only after testing), and an explicit `SESSION_DOMAIN` only if needed. Test session logout, expiry, CSRF and account suspension.
- [ ] Leave `CORS_ALLOWED_ORIGINS` empty unless a separately hosted browser client requires API access. If required, set an explicit comma-separated HTTPS origin allowlist. Native mobile HTTP clients do not need browser CORS.
- [ ] Set the mobile build-time `API_BASE_URL` to the production **HTTPS** endpoint. Do not commit production token or secret values. Verify Android platform permissions and secure-storage backup exclusion.
- [ ] Keep `FILESYSTEM_DISK=local` unless a tested private disk is configured. Private Work Order media belongs under `storage/app/private`, not `public/storage`; do not create a public link to private evidence. Set write permissions only for required storage/cache paths.
- [ ] Configure PHP/web-server upload and request-body limits to meet the application's `WORK_ORDER_ATTACHMENT_*` limits. Verify MIME, size and file-download authorization with test files.
- [ ] Configure mail only if intentionally enabled. In-app database notifications do not require mail. If queue-backed features are enabled, use a database queue plus a host-compatible cron-driven worker or a tested synchronous fallback; do not assume Redis/Horizon/daemon access.
- [ ] If scheduled tasks are introduced, configure a once-per-minute `artisan schedule:run` cron entry under the host's PHP CLI and verify it. Phase 12 does not require a scheduler for core workflows.
- [ ] Ensure `storage/logs` rotation and disk-space monitoring. Preserve historical evidence; do not silently purge it for space. Define retention and access policy for logs and backups.
- [ ] Build frontend assets, install production Composer dependencies, optimize Laravel caches **after** final environment values are in place, and verify the public health endpoint returns only `{"status":"ok"}`.
- [ ] Run the UAT plan in [UAT.md](UAT.md) on a staging/pilot copy before real users. Do not treat this checklist as human acceptance.

## Backup and recovery

Back up **both** MySQL and `storage/app/private` as a consistent recovery set. Also retain a secure copy of production `.env`/`APP_KEY` and deployment artifact/version. Encrypt backups, limit access, and keep an off-host copy. Choose and document a backup frequency and retention that meet campus policy. Test restore regularly; an untested backup is not a recovery plan.

Recovery rehearsal: (1) put the application into maintenance mode; (2) restore the matching MySQL dump and private evidence tree to a non-production rehearsal instance; (3) restore matching app code and protected configuration, including the same `APP_KEY`; (4) verify filesystem permissions; (5) clear/rebuild caches and inspect migration status; (6) test sign-in, one Work Order, one protected evidence download, and reporting; (7) reopen only after reconciliation. Never overwrite live data during a rehearsal. Document recovery point/time objectives with the owner.

## Release gate

The owner must complete Flutter SDK/analyze/tests/build and Android device/offline/account-switch tests, formal human UAT, production-host compatibility checks, backup restore rehearsal, and security review of the actual deployment configuration before pilot deployment. No shared-hosting configuration was changed by this Phase 12 work.
