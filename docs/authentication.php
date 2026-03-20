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
	<title>Authentication and MFA - <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
	<link href="../css/docs-site.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="docs-shell">
	<p><a href="index.php">Documentation</a> / Authentication and MFA</p>
	<div class="docs-content">
		<h1 class="h3">Authentication and MFA</h1>
		<p class="text-muted">Support for local credentials and optional Microsoft Entra ID sign-in.</p>
		<ul>
			<li>Local auth via Users/Roles/UserRoles tables (bcrypt).</li>
			<li>Entra OIDC with PKCE using <code>auth/azure/start.php</code> and <code>auth/azure/callback.php</code>.</li>
			<li>MFA challenge path at <code>mfa/challenge.php</code>.</li>
			<li>Supported factors: TOTP and WebAuthn passkeys.</li>
		</ul>
		<h2 class="h5 mt-3">Required Entra settings</h2>
		<ul>
			<li>Tenant ID, client ID, client secret.</li>
			<li>Redirect URI exactly matching your public callback URL.</li>
			<li>Optional admin group object ID mapping.</li>
		</ul>
	</div>
</div>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
