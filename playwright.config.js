// Playwright E2E tests for rsyslog-webui.
// Assumes `docker compose up` is running and the web UI is reachable at localhost:8080.

/** @type {import('@playwright/test').PlaywrightTestConfig} */
const config = {
  testDir: './tests/e2e',
  timeout: 30_000,
  retries: 0,
  use: {
    baseURL:
      process.env.BASE_URL ||
      `http://localhost:${process.env.WEB_PORT || 8080}`,
    viewport: { width: 1400, height: 900 },
    trace: 'off',
    ignoreHTTPSErrors: true
  }
};

module.exports = config;

