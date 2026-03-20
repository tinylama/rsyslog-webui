const { test, expect } = require('@playwright/test');

const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'admin123';

async function login(page) {
  await page.goto('/login.php');
  await page.locator('#username').fill(ADMIN_USER);
  await page.locator('#password').fill(ADMIN_PASS);
  await page.locator('#loginBtn').click();
  await expect(page.locator('#table-style')).toBeVisible();
  const skip = page.locator('[data-onboarding-skip="1"]');
  if ((await skip.count()) > 0) {
    await skip.click({ timeout: 1000 }).catch(() => {});
  }
}

async function fetchJsonViaPage(page, path) {
  await page.goto(path);
  const bodyText = await page.locator('body').innerText();
  return JSON.parse(bodyText.trim());
}

test.describe('Rsyslog WebUI - API smoke', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('events.php returns JSON and respects q= keyword search', async ({ page }) => {
    const keyword = 'Connection refused';

    const rows = await fetchJsonViaPage(page, `/json/events.php?q=${encodeURIComponent(keyword)}`);

    expect(Array.isArray(rows)).toBeTruthy();
    expect(rows.length).toBeGreaterThan(0);

    // All returned messages should include the keyword.
    const messages = rows.map((r) => r.Message || r.SmallMessage || '').join('\n');
    expect(messages).toContain(keyword);
  });

  test('events.php supports filterset search= (Severity)', async ({ page }) => {
    const filterset = `"Severity"="ERROR" `;

    const rows = await fetchJsonViaPage(page, `/json/events.php?search=${encodeURIComponent(filterset)}`);

    // Deterministic fixture includes exactly 1 ERROR record.
    expect(rows.length).toBe(1);
    expect(rows[0].Message).toContain('Connection refused occurred');
  });

  test('events.php returns 400 JSON for unknown filter field', async ({ page }) => {
    const res = await page.request.get(
      '/json/events.php?search=' + encodeURIComponent('"NotAColumn"="x"')
    );
    expect(res.status()).toBe(400);
    const body = await res.json();
    expect(body.error).toBe('invalid_filter_field');
  });

  test('events_summary.php sum of severities matches events.php length', async ({ page }) => {
    const keyword = 'Connection refused';

    const eventsRows = await fetchJsonViaPage(page, `/json/events.php?q=${encodeURIComponent(keyword)}`);

    const summary = await fetchJsonViaPage(page, `/json/events_summary.php?q=${encodeURIComponent(keyword)}`);

    expect(Array.isArray(summary)).toBeTruthy();
    const sum = summary.reduce((acc, item) => acc + (item[1] || 0), 0);
    expect(sum).toBe(eventsRows.length);
  });
});

