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
	<title>Documentation - <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
	<link href="../css/docs-site.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="docs-shell">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<a class="text-decoration-none" href="../"><i class="bi bi-arrow-left me-1"></i>Back to app</a>
		<div class="form-check form-switch m-0">
			<input class="form-check-input" type="checkbox" id="darkSwitch" aria-label="Dark mode">
			<label class="form-check-label small" for="darkSwitch">Dark</label>
		</div>
	</div>

	<div class="docs-hero mb-4">
		<h1 class="h3 mb-2">Documentation</h1>
		<p class="text-muted mb-0">Operator-focused guides for setup, authentication, deployment, testing, and security.</p>
	</div>

	<div class="row g-3">
		<div class="col-12 col-md-6">
			<a class="docs-card-link" href="installation.php">
				<h2 class="h5 mb-1"><i class="bi bi-rocket-takeoff me-2"></i>Installation</h2>
				<p class="small text-muted mb-0">Docker and manual install paths, rsyslog wiring, and first-run steps.</p>
			</a>
		</div>
		<div class="col-12 col-md-6">
			<a class="docs-card-link" href="authentication.php">
				<h2 class="h5 mb-1"><i class="bi bi-shield-lock me-2"></i>Authentication and MFA</h2>
				<p class="small text-muted mb-0">Local auth, Entra sign-in, TOTP, passkeys, and account security flow.</p>
			</a>
		</div>
		<div class="col-12 col-md-6">
			<a class="docs-card-link" href="deployment.php">
				<h2 class="h5 mb-1"><i class="bi bi-cloud-check me-2"></i>Deployment and TLS</h2>
				<p class="small text-muted mb-0">Production topology, TLS setup, and hardening checklist.</p>
			</a>
		</div>
		<div class="col-12 col-md-6">
			<a class="docs-card-link" href="testing.php">
				<h2 class="h5 mb-1"><i class="bi bi-check2-square me-2"></i>Testing and Verification</h2>
				<p class="small text-muted mb-0">Playwright setup, smoke/regression suites, and troubleshooting tips.</p>
			</a>
		</div>
	</div>

	<div class="docs-content mt-4">
		<h2 class="h6 mb-2">Repository docs</h2>
		<p class="small mb-0">Markdown docs remain available for GitHub readers in the <code>docs/</code> directory, including configuration, troubleshooting, security, and operations runbooks.</p>
	</div>
</div>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
