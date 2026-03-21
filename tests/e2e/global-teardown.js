/**
 * Removes Playwright-managed DB users (Username REGEXP '^e2e_') and deletes the token file.
 */
const fs = require('fs');
const path = require('path');
const { request } = require('@playwright/test');

const TOKEN_PATH = path.join(__dirname, '.e2e-bootstrap-token');

module.exports = async function globalTeardown() {
  if (!fs.existsSync(TOKEN_PATH)) {
    return;
  }
  const token = fs.readFileSync(TOKEN_PATH, 'utf8').trim();
  if (token.length < 32) {
    try {
      fs.unlinkSync(TOKEN_PATH);
    } catch (_) {}
    return;
  }

  const baseURL =
    process.env.BASE_URL ||
    `http://127.0.0.1:${process.env.WEB_PORT || 8080}`;

  let ctx;
  try {
    ctx = await request.newContext({ baseURL, ignoreHTTPSErrors: true });
    const res = await ctx.post('/json/e2e-bootstrap.php', {
      form: { token, action: 'cleanup' },
    });
    if (!res.ok()) {
      const body = await res.text().catch(() => '');
      console.warn(
        `[e2e teardown] cleanup returned ${res.status()}. ` +
          'You may need to delete Users WHERE Username REGEXP "^e2e_" manually. ' +
          (body ? `Body: ${body.slice(0, 200)}` : '')
      );
    }
  } catch (e) {
    console.warn(
      '[e2e teardown] cleanup request failed:',
      e && e.message ? e.message : e
    );
  } finally {
    if (ctx) {
      await ctx.dispose();
    }
    try {
      fs.unlinkSync(TOKEN_PATH);
    } catch (_) {}
  }
};
