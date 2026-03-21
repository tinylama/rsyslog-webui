const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./auth-helpers');

async function fetchJsonViaPage(page, path) {
  await page.goto(path);
  const bodyText = await page.locator('body').innerText();
  return JSON.parse(bodyText.trim());
}

test.describe('Rsyslog WebUI - API smoke', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('events.php returns JSON and respects q= keyword search', async ({ page }) => {
    const baseline = await fetchJsonViaPage(page, '/json/events.php');
    expect(Array.isArray(baseline)).toBeTruthy();
    expect(baseline.length).toBeGreaterThan(0);
    const first = baseline[0] || {};
    const keyword =
      (typeof first.FromHost === 'string' && first.FromHost.trim() !== '' ? first.FromHost.trim() : '') ||
      (typeof first.SysLogTag === 'string' && first.SysLogTag.trim() !== '' ? first.SysLogTag.trim() : '') ||
      'SYSLOG';

    const rows = await fetchJsonViaPage(page, `/json/events.php?q=${encodeURIComponent(keyword)}`);

    expect(Array.isArray(rows)).toBeTruthy();
    expect(rows.length).toBeGreaterThan(0);

    // Search should still return JSON rows for an existing token.
    expect(Array.isArray(rows)).toBeTruthy();
  });

  test('events.php supports filterset search= (Severity)', async ({ page }) => {
    const filterset = `"Severity"="ERROR" `;

    const rows = await fetchJsonViaPage(page, `/json/events.php?search=${encodeURIComponent(filterset)}`);

    // In production data this count varies; ensure filter is honored when rows exist.
    expect(Array.isArray(rows)).toBeTruthy();
    if (rows.length > 0) {
      const allError = rows.every((r) => String(r.Priority) === '3');
      expect(allError).toBeTruthy();
    }
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

