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
	<title>Deployment and TLS - <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
	<link href="../css/docs-site.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="docs-shell">
	<p><a href="index.php">Documentation</a> / Deployment and TLS</p>
	<div class="docs-content">
		<h1 class="h3">Deployment and TLS</h1>
		<p class="text-muted">Production mode uses Traefik and DNS-01 ACME challenge flow.</p>
		<h2 class="h5 mt-3">TLS stack startup</h2>
		<pre><code>cp docker/tls.env.example .env.tls
docker compose --env-file .env.tls -f docker-compose.tls.yml up -d --build</code></pre>
		<p>Use placeholder-safe values in tracked files only (for example, <code>logs.example.com</code>).</p>
		<h2 class="h5 mt-3">Hardening checklist</h2>
		<ul>
			<li>Set strong session and app secrets.</li>
			<li>Store OAuth/API secrets outside source control.</li>
			<li>Enable HTTPS and verify forwarded headers.</li>
			<li>Validate backup and restore procedures.</li>
		</ul>
	</div>
</div>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
