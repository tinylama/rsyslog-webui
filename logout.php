<?php
require_once __DIR__ . '/auth.php';

$azureOut = (($_SESSION['auth_provider'] ?? '') === 'azure');
auth_logout();

if ($azureOut) {
	require_once __DIR__ . '/includes/azure-oidc.php';
	if (auth_azure_enabled() && auth_azure_is_configured()) {
		$t = auth_azure_settings()['tenant_id'];
		$forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
		$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		if ($forwardedProto !== '') {
			// X-Forwarded-Proto can be a comma-separated list (proxy chains).
			$https = $https || str_contains($forwardedProto, 'https');
		}
		$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
		$redir = rawurlencode($base . '/');
		header(
			'Location: https://login.microsoftonline.com/' . rawurlencode($t) . '/oauth2/v2.0/logout?post_logout_redirect_uri=' . $redir,
			true,
			302
		);
		exit;
	}
}

header('Location: /', true, 302);
exit;
