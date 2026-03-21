# Testing

## Test Types

- `tests/e2e/api-smoke.spec.js`: API behavior checks.
- `tests/e2e/rsyslog-webui.e2e.spec.js`: main user journeys and regressions.
- `tests/e2e/mobile-view.spec.js`: mobile viewport rendering checks.

## Prerequisites

```bash
npm install
npx playwright install chromium
```

Start app stack first:

```bash
docker compose up -d --build
```

### E2E sign-in (bootstrap token)

Playwright uses a **per-run token** (written to `tests/e2e/.e2e-bootstrap-token` by `global-setup.js`) instead of hard-coded admin passwords. The web container must:

- Be **Docker dev** (`/.dockerenv` present) so the bootstrap endpoint is allowed.
- Have **`RSYSLOG_E2E_ENABLED=1`** (set in `docker-compose.yml` and TLS compose variants used for local testing).
- **Bind-mount the repo** (default in compose) so PHP can read the same token file under `tests/e2e/`.

`POST /json/e2e-bootstrap.php` with `token=<value>` and `action=session` creates or reuses a local admin user `e2e_bootstrap` (password is random and unused) and sets the session cookie. **`global-teardown.js`** posts `action=cleanup` to delete **all** users whose username matches `^e2e_` (including `e2e_bootstrap` and any `e2e_user_*` rows) so test accounts are not left behind.

**Production / hardened Docker:** set `RSYSLOG_E2E_ENABLED=0` or omit it; the bootstrap URL then returns `404` and is not advertised.

**Emergency password login:** set `E2E_LEGACY_ADMIN_LOGIN=1` and valid `ADMIN_USER` / `ADMIN_PASS` (or use first-login setup) if you must debug without the token file.

**Login rate limiting:** failed sign-in attempts are tracked in `LoginRateLimit`. Dev compose sets `RSYSLOG_LOGIN_RATE_LIMIT=0` so Playwright is not blocked after repeated runs. For production-like stacks, keep rate limiting on (`1` or unset). To unstick a locked account without recreating the DB: `DELETE FROM LoginRateLimit;` in MySQL.

## Run Tests

PowerShell examples:

```powershell
$env:BASE_URL='http://localhost:8080'
npx playwright test
```

If using a custom web port:

```powershell
$env:BASE_URL='http://localhost:8888'
npx playwright test
```

If testing against the **production TLS** stack (Traefik on `443`), point Playwright at HTTPS. `playwright.config.js` sets `ignoreHTTPSErrors: true` for self-signed or local dev certs:

```powershell
$env:BASE_URL='https://localhost'
npx playwright test
```

Use `docker-compose.tls.yml` (ACME) or `docker-compose.tls-manual.yml` (manual certs) as documented in [Deployment](deployment.md).

## Focused Runs

```bash
npx playwright test tests/e2e/rsyslog-webui.e2e.spec.js
npx playwright test tests/e2e/mobile-view.spec.js
npx playwright test -g "webauthn"
```

## Lighthouse

Run with the app stack active.

**Dev** (`docker-compose.yml`, web on port `8080`):

```bash
npm run lighthouse:logs
npm run lighthouse:docs
```

**HTTPS / Traefik** (e.g. `127.0.0.1` with self-signed or local TLS):

```bash
npm run lighthouse:logs:https
npm run lighthouse:docs:https
```

Equivalent one-off:

```bash
npx lighthouse https://127.0.0.1/ --chrome-flags="--headless --no-sandbox --ignore-certificate-errors" --only-categories=performance,accessibility,best-practices,seo --output=html,json --output-path=./lighthouse-logs
```

On some Windows hosts Lighthouse may exit with a temp-folder `EPERM` after writing the report; the JSON/HTML files are still produced.

**Public landing CSS:** the logged-out landing uses `css/landing-public.css`, a merge of `layout.css`, `dark-mode.css`, and `landing.css` plus minimal Bootstrap variable fallbacks (see `scripts/build-landing-public-css.mjs`). After editing any of those three files, run `npm run build:landing-css` before committing. **Do not defer** the main `bootstrap.min.css` link on this page—lab FCP/LCP typically regress because the hero relies on Bootstrap’s grid and components. Bottom scripts use `defer` so they are not parser-blocking.

If PageSpeed still lists old asset URLs (e.g. separate `layout.css` / `landing.css` instead of `landing-public.css`), the HTML is likely cached at the CDN or browser—purge cache after deploy.

Reports are written next to the repo root (names from `--output-path`):

- `lighthouse-logs.report.html` / `lighthouse-logs.report.json`
- `lighthouse-docs.report.html` / `lighthouse-docs.report.json`

## Ingestion Smoke Checks

Collector path:

```bash
logger -n 127.0.0.1 -P 5514 -d -t rsyslog-webui-test "collector smoke"
```

Database verification:

```bash
docker exec rsyslog-webui-db-1 mysql -ursyslog -prsyslog rsyslogdb -e "SELECT ID,FromHost,SysLogTag,LEFT(Message,120) msg FROM SystemEvents ORDER BY ID DESC LIMIT 5;"
```

## Troubleshooting

- If tests fail to find elements, confirm app endpoint returns full HTML (not empty/redirect-only responses).
- Validate the active URL/port and TLS mode match `BASE_URL`.
- Ensure Docker compose includes `RSYSLOG_E2E_ENABLED=1` and the repo mount when using the default bootstrap flow (no manual admin password needed).
