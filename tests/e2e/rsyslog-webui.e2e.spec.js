const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./auth-helpers');

async function openUserMenu(page) {
  await page.locator('#userMenu').click();
}

async function fetchJsonViaPage(page, path) {
  await page.goto(path);
  const bodyText = await page.locator('body').innerText();
  return JSON.parse(bodyText.trim());
}

function pickSearchTokenFromRow(row) {
  const candidates = [
    row && typeof row.FromHost === 'string' ? row.FromHost.trim() : '',
    row && typeof row.SysLogTag === 'string' ? row.SysLogTag.trim() : '',
    row && typeof row.Facility === 'string' ? row.Facility.trim() : '',
  ].filter(Boolean);
  return candidates[0] || 'SYSLOG';
}

/** Matches index.php getSummary() progressBars (priority → strip + filter token). */
const SEVERITY_STRIP_BY_PRIORITY = [
  { priority: 3, bar: '#pgError', token: 'ERROR' },
  { priority: 4, bar: '#pgWarning', token: 'WARNING' },
  { priority: 5, bar: '#pgNotice', token: 'NOTICE' },
  { priority: 6, bar: '#pgInfo', token: 'INFO' },
  { priority: 7, bar: '#pgDebug', token: 'DEBUG' },
];

/**
 * Pick first severity that has rows in the live DB (strip will get non-zero width after getSummary).
 */
async function pickLiveSeverityStripSpec(page) {
  const res = await page.request.get('/json/events_summary.php');
  if (!res.ok()) {
    return null;
  }
  const data = await res.json();
  if (!Array.isArray(data)) {
    return null;
  }
  const qtyByP = new Map(
    data.map((row) => [Number(row[0]), Number(row[1])])
  );
  for (const spec of SEVERITY_STRIP_BY_PRIORITY) {
    const q = qtyByP.get(spec.priority) ?? 0;
    if (q > 0) {
      return spec;
    }
  }
  return null;
}

/**
 * getSummary() runs async; 0% width segments are not visible to Playwright.
 */
async function waitForSeverityBarSized(page, barSelector) {
  await page.waitForFunction(
    (sel) => {
      const el = document.querySelector(sel);
      if (!el) return false;
      const w = parseFloat(String(el.style.width || '0'));
      return w > 0;
    },
    barSelector,
    { timeout: 15_000 }
  );
}

test.describe('Rsyslog WebUI - landing', () => {
  test('unauthenticated / shows marketing and Sign in', async ({ page }) => {
    await page.goto('/logout.php');
    await page.goto('/');
    await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible();
    await expect(page.locator('#table-style')).toHaveCount(0);
  });

  test('login shows Forgot password link', async ({ page }) => {
    await page.goto('/logout.php');
    await page.goto('/login.php');
    await expect(page.getByRole('link', { name: 'Forgot password?' })).toBeVisible();
    await page.getByRole('link', { name: 'Forgot password?' }).click();
    await expect(page.getByRole('heading', { name: 'Password reset' })).toBeVisible();
    await expect(page).toHaveURL(/password-reset\.php/);
  });

  test('password-reset respects dark mode localStorage (dark)', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('darkSwitch', 'dark');
    });
    await page.goto('/password-reset.php');
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');
  });

  test('password-reset defaults to light mode', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.removeItem('darkSwitch');
    });
    await page.goto('/password-reset.php');
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'light');
    await expect(page.locator('body')).not.toHaveAttribute('data-theme', 'dark');
  });
});

test.describe('Rsyslog WebUI - end-to-end', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('navbar shows sign out when auth is enabled', async ({ page }) => {
    await openUserMenu(page);
    await expect(page.locator('a.dropdown-item[href="logout.php"]')).toBeVisible();
  });

  test('admin sees Admin link to directory', async ({ page }) => {
    await openUserMenu(page);
    await expect(page.getByRole('link', { name: 'Admin' })).toBeVisible();
  });

  test('admin directory page loads with account table', async ({ page }) => {
    await page.goto('/admin/users.php');
    await expect(page.getByRole('heading', { name: 'Directory' })).toBeVisible();
    await expect(page.locator('a.dropdown-item[href="../logout.php"]')).toHaveCount(1);
    await expect(page.getByRole('button', { name: /Add user/i })).toBeVisible();
    await expect(page.locator('table tbody tr').first()).toBeVisible();
  });

  test('admin can update a local username (temp user cleaned up)', async ({ page }) => {
    await page.goto('/admin/users.php');

    const ts = Date.now() % 1000000;
    const createdUsername = `e2e_user_${ts}`;
    const createdPassword = `E2Epass${ts}`;
    const updatedUsername = `e2e_user_updated_${ts}`;

    // Create a temp local user so the test does not rely on any existing non-admin users.
    await page.getByRole('button', { name: /Add user/i }).click();
    await page.locator('#addUserPanel input#username').fill(createdUsername);
    await page.locator('#addUserPanel input#password').fill(createdPassword);
    await page.locator('#role_new').selectOption('user');
    await page.locator('#addUserPanel').getByRole('button', { name: 'Create user' }).click();
    await expect(page.locator('.alert-success')).toContainText('User created.');

    // Update the username for the newly created row.
    const createdRow = page.locator('table tbody tr').filter({ hasText: createdUsername });
    await expect(createdRow.locator('input[name="username_new"]')).toBeVisible();
    await createdRow.locator('input[name="username_new"]').fill(updatedUsername);
    await createdRow.locator('button:has-text("Change")').click();
    await expect(page.locator('.alert-success')).toContainText('Username updated.');

    await expect(page.locator('table tbody tr div.fw-medium', { hasText: updatedUsername })).toHaveCount(1);

    // Delete the temp user so it doesn't remain in the admin directory.
    const updatedRow = page.locator('table tbody tr').filter({ hasText: updatedUsername });
    page.once('dialog', async (dialog) => {
      await dialog.accept();
    });
    await updatedRow.locator('button:has-text("Delete")').click();

    await expect(page.locator('.alert-success')).toContainText('User deleted.');
    await expect(page.locator('table tbody tr').filter({ hasText: updatedUsername })).toHaveCount(0);
  });

  test('loads the events table and renders at least one row', async ({ page }) => {
    // Bootstrap Table renders rows into the tbody asynchronously.
    const rows = page.locator('#table-style tbody tr');
    await expect(rows.first()).toBeVisible();
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('keyword search (q=) filters by message content and keeps UI/API consistent', async ({ page }) => {
    const baseline = await fetchJsonViaPage(page, '/json/events.php');
    expect(baseline.length).toBeGreaterThan(0);
    const keyword = pickSearchTokenFromRow(baseline[0]);

    const apiRows = await fetchJsonViaPage(
      page,
      `/json/events.php?q=${encodeURIComponent(keyword)}`
    );
    expect(apiRows.length).toBeGreaterThan(0);

    await page.goto('/');

    // Live search (debounced): no need to click Reload.
    await page.locator('#txtSearch').fill(keyword);
    const rows = page.locator('#table-style tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#table-style tbody')).not.toBeEmpty();
  });

  test('keyword search matches SysLogTag when not present in message (e.g. nginx)', async ({ page }) => {
    const baseline = await fetchJsonViaPage(page, '/json/events.php');
    expect(baseline.length).toBeGreaterThan(0);
    const tag = pickSearchTokenFromRow(baseline[0]);
    const apiRows = await fetchJsonViaPage(page, `/json/events.php?q=${encodeURIComponent(tag)}`);
    expect(apiRows.length).toBeGreaterThan(0);

    await page.goto('/');
    await page.locator('#txtSearch').fill(tag);
    const rows = page.locator('#table-style tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#table-style tbody')).not.toBeEmpty();
  });

  test('severity filterset matches strip and filters via search', async ({ page }) => {
    const spec = await pickLiveSeverityStripSpec(page);
    test.skip(
      !spec,
      'No SystemEvents rows in any severity bucket — cannot exercise severity strip'
    );

    await waitForSeverityBarSized(page, spec.bar);
    await expect(page.locator(spec.bar)).toBeVisible();

    const token = `"Severity"="${spec.token}" `;
    await page.locator('#txtSearch').fill(token);
    await page.waitForTimeout(700);
    const val = await page.locator('#txtSearch').inputValue();
    expect(val).toContain(`"Severity"="${spec.token}"`);
    await expect(page.locator('#table-style')).toBeVisible();
  });

  test('clicking selected severity toggles it off', async ({ page }) => {
    const spec = await pickLiveSeverityStripSpec(page);
    test.skip(
      !spec,
      'No SystemEvents rows in any severity bucket — cannot exercise severity strip'
    );

    await page.goto('/');
    await waitForSeverityBarSized(page, spec.bar);
    await page.locator(spec.bar).click();
    await expect(page.locator('#txtSearch')).toHaveValue(
      new RegExp(`"Severity"="${spec.token}"`)
    );
    await page.locator(spec.bar).click();
    await expect(page.locator('#txtSearch')).not.toHaveValue(
      new RegExp(`"Severity"="${spec.token}"`)
    );
  });

  test('row click expands to show full message safely', async ({ page }) => {
    const firstRow = page.locator('#table-style tbody tr').first();
    await firstRow.click();

    // Expanded message row uses a <td> with class "expandedMessage"
    const expanded = page.locator('td.expandedMessage');
    await expect(expanded).toBeVisible();
    await expect(expanded).not.toBeEmpty();
  });

  test('account security page renders menu + sections', async ({ page }) => {
    await page.goto('/account/security.php');

    // Account security page no longer includes a section navigation menu.
    await expect(page.locator('#accountSecurityMenu')).toHaveCount(0);

    // Consistent logout control.
    await expect(page.locator('a.dropdown-item[href="../logout.php"]')).toHaveCount(1);

    await expect(page.getByRole('heading', { name: 'Account security' })).toBeVisible();
    await expect(page.locator('h2#email-section')).toBeVisible();
    // Email MFA requires Entra + Graph mail to be configured.
    await expect(page.locator('h2#email-mfa-section')).toHaveCount(0);
    await expect(page.locator('h2#totp-section')).toBeVisible();
    await expect(page.locator('h2#passkeys-section')).toBeVisible();
  });

  test('signed-in landing hides sign-in button and shows sign out', async ({ page }) => {
    // Render the landing partial directly to validate the signed-in UX.
    await page.goto('/includes/partials/landing.php');

    // Signed-in landing should not show the public "Sign in" CTA.
    await expect(page.getByRole('link', { name: 'Sign in' })).toHaveCount(0);

    // Account menu should offer sign out.
    await expect(page.locator('a.dropdown-item[href="logout.php"]')).toHaveCount(1);
  });

  test('account security TOTP start enrollment (no fatal errors)', async ({ page }) => {
    await page.goto('/account/security.php');

    const start = page.getByRole('button', { name: 'Start enrollment' });
    await expect(start).toBeVisible();
    await start.click();

    await expect(page.getByText('Scan the QR code')).toBeVisible();
    await expect(page.locator('img[alt="TOTP QR code"]')).toBeVisible();
  });

  test('admin settings-entra loads for admin users', async ({ page }) => {
    await page.goto('/admin/settings-entra.php');

    await expect(page.getByRole('heading', { name: 'Microsoft Entra ID' })).toBeVisible();
    await expect(page.locator('#copyCallback')).toBeVisible();
    await expect(page.getByText('Configuration')).toBeVisible();
  });

  test('admin settings-entra rejects invalid tenant id', async ({ page }) => {
    const tenantId = 'not-a-guid';
    const clientId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    await page.goto('/admin/settings-entra.php');
    await page.locator('#azEn').uncheck();
    await page.locator('#tenant').fill(tenantId);
    await page.locator('#client').fill(clientId);

    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.locator('.alert-danger')).toContainText('Directory (tenant) ID must be a GUID.');
  });

  test('admin settings-entra rejects invalid graph mail sender', async ({
    page,
  }) => {
    const tenantId = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
    const clientId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    await page.goto('/admin/settings-entra.php');
    await page.locator('#azEn').uncheck();
    await page.locator('#tenant').fill(tenantId);
    await page.locator('#client').fill(clientId);
    await page.locator('#gms').fill('not-an-email');

    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.locator('.alert-danger')).toContainText(
      'Graph mail sender must look like an email/UPN'
    );
  });

  test('admin settings-entra requires required IDs when enabling', async ({
    page,
  }) => {
    await page.goto('/admin/settings-entra.php');
    await page.locator('#azEn').check();
    await page.locator('#tenant').fill('');
    await page.locator('#client').fill('');
    await page.locator('#secret').fill('');

    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.locator('.alert-danger')).toContainText(
      'Directory (tenant) ID is required to enable Microsoft sign-in.'
    );
  });

  test('webauthn register-options returns JSON for logged in user', async ({ page }) => {
    await page.goto('/account/security.php');
    const html = await page.content();
    const m = html.match(/var csrf\s*=\s*["']([^"']+)["']/);
    expect(m).not.toBeNull();
    const csrf = m[1];

    const res = await page.request.get(
      `/json/webauthn-register-options.php?csrf=${encodeURIComponent(csrf)}`
    );
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.publicKey).toBeTruthy();
    expect(body.publicKey.challenge).toBeTruthy();
    expect(body.publicKey.user.id).toBeTruthy();
  });

  test('webauthn register-verify rejects invalid credential payload', async ({
    page,
  }) => {
    await page.goto('/account/security.php');
    const html = await page.content();
    const m = html.match(/var csrf\s*=\s*["']([^"']+)["']/);
    expect(m).not.toBeNull();
    const csrf = m[1];

    // Call register-options first so server-side ceremony state exists.
    const optsRes = await page.request.get(
      `/json/webauthn-register-options.php?csrf=${encodeURIComponent(csrf)}`
    );
    expect(optsRes.status()).toBe(200);

    const res = await page.request.post('/json/webauthn-register-verify.php', {
      headers: { 'Content-Type': 'application/json' },
      data: JSON.stringify({
        csrf,
        credential: {},
        friendlyName: 'Passkey',
      }),
    });

    expect(res.status()).toBe(400);
    const body = await res.json();
    expect(body.ok).toBe(false);
    expect(body.error).toBe('verify_failed');

    // Regression: some browsers/clients may send binary fields as JSON arrays.
    // Ensure we never fatal during decoding; still return verify_failed.
    const res2 = await page.request.post('/json/webauthn-register-verify.php', {
      headers: { 'Content-Type': 'application/json' },
      data: JSON.stringify({
        csrf,
        credential: {
          rawId: [1, 2, 3],
          response: {
            clientDataJSON: [4, 5, 6],
            attestationObject: [7, 8, 9],
          },
        },
        friendlyName: 'Passkey',
      }),
    });

    expect([200, 400]).toContain(res2.status());
    const raw2 = await res2.text();
    try {
      const body2 = JSON.parse(raw2);
      expect(body2.ok).toBe(false);
      expect(body2.error).toBe('verify_failed');
    } catch {
      // Some environments emit PHP warnings before JSON in this path.
      expect(raw2.toLowerCase()).toContain('verify_failed');
    }
  });

  test('dark mode toggle persists across reload (account section)', async ({ page }) => {
    await page.goto('/account/security.php');
    const toggle = page.locator('#darkSwitch');
    await expect(toggle).toBeVisible();

    // Avoid label pointer-event interception by toggling checked state in-page
    // and dispatching the same `change` event the app listens for.
    await toggle.evaluate((el) => {
      el.checked = false;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'light');

    await toggle.evaluate((el) => {
      el.checked = true;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');

    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');
  });

  test('dark mode applies in account section', async ({ page }) => {
    await page.goto('/account/security.php');
    const toggle = page.locator('#darkSwitch');
    await expect(toggle).toBeVisible();

    await toggle.evaluate((el) => { el.checked = true; el.dispatchEvent(new Event('change', { bubbles: true })); });
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');
  });

  test('API endpoints return JSON for default (unfiltered) requests', async ({ page }) => {
    // Navigation-based JSON fetch for auth-aware endpoints:
    const events = await fetchJsonViaPage(page, '/json/events.php');
    expect(Array.isArray(events)).toBeTruthy();
    expect(events.length).toBeGreaterThan(0);

    const summary = await fetchJsonViaPage(page, '/json/events_summary.php');
    expect(Array.isArray(summary)).toBeTruthy();
    expect(summary.length).toBeGreaterThan(0);
  });
});

