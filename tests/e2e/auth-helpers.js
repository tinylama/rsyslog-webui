const fs = require('fs');
const path = require('path');
const { expect } = require('@playwright/test');

const TOKEN_PATH = path.join(__dirname, '.e2e-bootstrap-token');

/** @deprecated Prefer E2E bootstrap; kept for emergency local debugging only. */
const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'admin123';
const ADMIN_SETUP_PASS = process.env.ADMIN_SETUP_PASS || 'AdminPass123!';

/**
 * @returns {string}
 */
function readE2EBootstrapToken() {
  const fromEnv = (process.env.RSYSLOG_E2E_TOKEN || process.env.E2E_BOOTSTRAP_TOKEN || '').trim();
  if (fromEnv.length >= 32) {
    return fromEnv;
  }
  try {
    const t = fs.readFileSync(TOKEN_PATH, 'utf8').trim();
    return t.length >= 32 ? t : '';
  } catch {
    return '';
  }
}

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>}
 */
async function loginViaE2EBootstrap(page) {
  const token = readE2EBootstrapToken();
  if (!token) {
    return false;
  }
  const res = await page.request.post('/json/e2e-bootstrap.php', {
    form: { token, action: 'session' },
  });
  if (!res.ok()) {
    return false;
  }
  let body;
  try {
    body = await res.json();
  } catch {
    return false;
  }
  return body && body.ok === true && body.action === 'session';
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} username
 * @param {string} password
 */
async function submitLogin(page, username, password) {
  await page.locator('#username').fill(username);
  await page.locator('#password').fill(password);
  await page.locator('#loginBtn').click();
  await page.waitForLoadState('load');
}

function stillOnLoginPage(page) {
  return page.url().includes('login.php');
}

/**
 * Dismiss first-run onboarding overlay if present (dev/docker).
 * @param {import('@playwright/test').Page} page
 */
async function dismissOnboardingIfPresent(page) {
  const skip = page.locator('[data-onboarding-skip="1"]');
  if ((await skip.count()) > 0) {
    await skip.click({ timeout: 1000 }).catch(() => {});
  }
}

/**
 * Sign in as admin and wait for the main log viewer (#table-style).
 * Uses Docker-only E2E bootstrap when a token file is present (see global-setup.js).
 * Set E2E_LEGACY_ADMIN_LOGIN=1 to force username/password login instead.
 *
 * @param {import('@playwright/test').Page} page
 */
async function loginAsAdmin(page) {
  const legacy = process.env.E2E_LEGACY_ADMIN_LOGIN === '1';

  if (!legacy && (await loginViaE2EBootstrap(page))) {
    await page.goto('/');
    await dismissOnboardingIfPresent(page);
    await expect(page.locator('#table-style')).toBeVisible({ timeout: 15_000 });
    return;
  }

  if (!legacy && readE2EBootstrapToken()) {
    throw new Error(
      'E2E bootstrap login failed. Ensure docker compose web has RSYSLOG_E2E_ENABLED=1, ' +
        'the repo is bind-mounted, and tests/e2e/.e2e-bootstrap-token exists (Playwright global setup). ' +
        'Or set E2E_LEGACY_ADMIN_LOGIN=1 with valid ADMIN_USER/ADMIN_PASS for debugging.'
    );
  }

  await page.goto('/login.php');
  await page.waitForLoadState('domcontentloaded');

  const isFirstLoginSetup =
    (await page.getByRole('heading', { name: /First login setup/i }).count()) > 0;

  if (isFirstLoginSetup) {
    await submitLogin(page, ADMIN_USER, ADMIN_SETUP_PASS);
  } else {
    await submitLogin(page, ADMIN_USER, ADMIN_PASS);
    if (stillOnLoginPage(page)) {
      await submitLogin(page, ADMIN_USER, ADMIN_SETUP_PASS);
    }
    if (stillOnLoginPage(page)) {
      throw new Error(
        'Login failed: still on login.php after trying default passwords. ' +
          'Run Playwright with global setup (E2E token) or set ADMIN_USER/ADMIN_PASS. (auth-helpers.js)'
      );
    }
  }

  await dismissOnboardingIfPresent(page);
  await expect(page.locator('#table-style')).toBeVisible({ timeout: 15_000 });
}

module.exports = {
  loginAsAdmin,
  dismissOnboardingIfPresent,
  readE2EBootstrapToken,
  ADMIN_USER,
  ADMIN_PASS,
  ADMIN_SETUP_PASS,
};
