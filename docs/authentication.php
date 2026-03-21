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
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
	<link href="../css/docs-site.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="docs-shell">
	<?php $docsActive = 'authentication'; include __DIR__ . '/../includes/partials/docs-nav.php'; ?>
	<div class="docs-content">
		<h1 class="h3">Authentication and MFA</h1>
		<p class="text-muted">Support for local credentials and optional Microsoft Entra ID sign-in.</p>
		<ul>
			<li>Local auth via Users/Roles/UserRoles tables (bcrypt).</li>
			<li>Entra OIDC with PKCE using <code>auth/azure/start.php</code> and <code>auth/azure/callback.php</code>.</li>
			<li>MFA challenge path at <code>mfa/challenge.php</code>.</li>
			<li>Supported factors: TOTP and WebAuthn passkeys.</li>
		</ul>
		<h2 class="h5 mt-3">Local MFA enforcement</h2>
		<p>Admins can enforce MFA for all local accounts from Admin → Directory. When enabled, local users must have TOTP or passkey enrolled before successful sign-in.</p>
		<h2 class="h5 mt-3">Auditability</h2>
		<ul>
			<li>Admin → Logs includes sign-in logs (allowed/failed), access logs (allowed/blocked), and email delivery logs.</li>
			<li>Account → Security shows per-user recent sign-in activity.</li>
		</ul>
		<h2 class="h5 mt-3">Required Entra settings</h2>
		<ul>
			<li>Tenant ID, client ID, client secret.</li>
			<li>Redirect URI exactly matching your public callback URL.</li>
			<li>Optional admin group object ID mapping.</li>
		</ul>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
