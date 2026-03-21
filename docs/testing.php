<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
$siteTitle = htmlspecialchars((string) ($site_name ?? 'System Logs'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Testing and Verification - <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
	<link href="../css/docs-site.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="docs-shell">
	<?php $docsActive = 'testing'; include __DIR__ . '/../includes/partials/docs-nav.php'; ?>
	<div class="docs-content">
		<h1 class="h3">Testing and Verification</h1>
		<p class="text-muted">Playwright suites cover API behavior, UX regressions, and mobile views.</p>
		<h2 class="h5 mt-3">Setup</h2>
		<pre><code>npm install
npx playwright install chromium</code></pre>
		<h2 class="h5 mt-3">Run full suite</h2>
		<pre><code>npx playwright test</code></pre>
		<h2 class="h5 mt-3">Useful focused runs</h2>
		<pre><code>npx playwright test tests/e2e/mobile-view.spec.js
npx playwright test -g "webauthn"</code></pre>
		<h2 class="h5 mt-3">Lighthouse performance runs</h2>
		<pre><code>npm run lighthouse:logs
npm run lighthouse:docs
npm run lighthouse:logs:https
npm run lighthouse:docs:https</code></pre>
		<p class="small text-muted">Start the app stack first. Dev scripts use <code>http://localhost:8080</code>. Traefik/TLS: use the <code>:https</code> scripts (or <code>https://127.0.0.1/…</code> with <code>--ignore-certificate-errors</code>). Outputs: <code>lighthouse-logs.report.html</code> / <code>.report.json</code> (and docs equivalents). See <a href="testing.md">testing.md</a>.</p>
		<p class="small text-muted">Production TLS tests: set <code>BASE_URL</code> to your HTTPS origin (Playwright config allows insecure certs for local/self-signed).</p>
		<h2 class="h5 mt-3">Security verification checklist</h2>
		<ul>
			<li>Verify login rate limiting triggers after repeated failures.</li>
			<li>Verify HTTPS whitelist blocks disallowed IPs and records Access Logs.</li>
			<li>Verify local MFA policy blocks unenrolled local sign-ins.</li>
			<li>Verify sign-in/access/email log records in Admin → Logs.</li>
		</ul>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
