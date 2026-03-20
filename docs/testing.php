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
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
	<link href="../css/docs-site.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="docs-shell">
	<p><a href="index.php">Documentation</a> / Testing and Verification</p>
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
	</div>
</div>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
