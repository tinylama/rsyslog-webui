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

## Focused Runs

```bash
npx playwright test tests/e2e/rsyslog-webui.e2e.spec.js
npx playwright test tests/e2e/mobile-view.spec.js
npx playwright test -g "webauthn"
```

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
- Ensure seeded admin credentials are still present in test DB fixtures.
