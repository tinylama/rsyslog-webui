/**
 * Generates a per-run bootstrap token for json/e2e-bootstrap.php (bind-mounted into the web container).
 * Playwright workers read the same file via auth-helpers.js (globalSetup env is not propagated).
 */
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const TOKEN_PATH = path.join(__dirname, '.e2e-bootstrap-token');

module.exports = async function globalSetup() {
  const token = crypto.randomBytes(32).toString('hex');
  fs.writeFileSync(TOKEN_PATH, token, { encoding: 'utf8', mode: 0o600 });
};
