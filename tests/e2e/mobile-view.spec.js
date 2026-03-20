const { test, expect } = require('@playwright/test');

const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'admin123';

async function login(page) {
  await page.goto('/login.php');
  await page.locator('#username').fill(ADMIN_USER);
  await page.locator('#password').fill(ADMIN_PASS);
  await page.locator('#loginBtn').click();
  await page.waitForURL('**/', { timeout: 10_000 });

  // First-login onboarding is non-blocking, but dismiss it to keep selectors stable.
  const skip = page.locator('[data-onboarding-skip="1"]');
  if ((await skip.count()) > 0) {
    await skip.click({ timeout: 1000 }).catch(() => {});
  }

  await expect(page.locator('#table-style')).toBeVisible();
}

test.describe('Mobile view - unauthenticated', () => {
  test.use({ viewport: { width: 390, height: 844 } }); // iPhone 12/13-ish

  test('landing shows sign-in CTA', async ({ page }) => {
    await page.goto('/logout.php');
    await page.goto('/');

    await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible();
    await page.screenshot({ path: 'test-results/mobile-landing-unauth.png', fullPage: true });
  });

  test('login form is usable', async ({ page }) => {
    await page.goto('/login.php');
    await expect(page.locator('#username')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.getByRole('button', { name: /sign in/i })).toBeVisible();
    await page.screenshot({ path: 'test-results/mobile-login.png', fullPage: true });
  });

  test('password reset page renders', async ({ page }) => {
    await page.goto('/password-reset.php');
    await expect(page.getByRole('heading', { name: 'Password reset' })).toBeVisible();
    await expect(page.locator('#email')).toBeVisible();
    await page.screenshot({ path: 'test-results/mobile-password-reset.png', fullPage: true });
  });
});

test.describe('Mobile view - authenticated', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('log viewer navbar + table render', async ({ page }) => {
    await expect(page.locator('nav[role="navigation"].app-navbar')).toBeVisible();
    await expect(page.locator('#table-style')).toBeVisible();

    // On mobile, Bootstrap should collapse the navbar content behind a toggler.
    await expect(page.locator('.navbar-toggler[data-bs-target="#mainNavbar"]')).toBeVisible();

    await page.screenshot({ path: 'test-results/mobile-logs.png', fullPage: true });
  });

  test('account security layout shows sections', async ({ page }) => {
    await page.goto('/account/security.php');
    await expect(page.getByRole('heading', { name: 'Account security' })).toBeVisible();
    await expect(page.locator('h2#totp-section')).toBeVisible();
    await expect(page.locator('h2#passkeys-section')).toBeVisible();
    await page.screenshot({ path: 'test-results/mobile-account-security.png', fullPage: true });
  });

  test('admin directory layout shows table', async ({ page }) => {
    await page.goto('/admin/users.php');
    await expect(page.getByRole('heading', { name: 'Directory' })).toBeVisible();
    await expect(page.locator('table')).toBeVisible();
    await page.screenshot({ path: 'test-results/mobile-admin-directory.png', fullPage: true });
  });

  test('admin Entra settings layout renders', async ({ page }) => {
    await page.goto('/admin/settings-entra.php');
    await expect(page.getByRole('heading', { name: 'Microsoft Entra ID' })).toBeVisible();
    await expect(page.locator('#copyCallback')).toBeVisible();
    await page.screenshot({ path: 'test-results/mobile-admin-entra.png', fullPage: true });
  });

  test('admin HTTPS whitelist layout renders', async ({ page }) => {
    await page.goto('/admin/settings-https.php');
    await expect(page.getByRole('heading', { name: 'HTTPS whitelist' })).toBeVisible();
    await expect(page.locator('textarea#cidrs_input')).toBeVisible();
    await page.screenshot({ path: 'test-results/mobile-admin-https.png', fullPage: true });
  });
});

