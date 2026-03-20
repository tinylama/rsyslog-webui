<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/azure-oidc.php';

if (!auth_is_enabled()) {
	http_response_code(404);
	exit;
}
if (!auth_azure_enabled() || !auth_azure_is_configured()) {
	http_response_code(404);
	exit;
}

$settings = auth_azure_settings();
$redirectUri = auth_azure_effective_redirect_uri($settings);
$next = auth_safe_next(isset($_GET['next']) && is_string($_GET['next']) ? $_GET['next'] : '/');

$_SESSION['oauth2_next'] = $next;
$_SESSION['oauth2_state'] = bin2hex(random_bytes(16));
$verifier = bin2hex(random_bytes(32));
$_SESSION['oauth2_code_verifier'] = $verifier;
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

$params = http_build_query([
	'client_id' => $settings['client_id'],
	'response_type' => 'code',
	'redirect_uri' => $redirectUri,
	'scope' => 'openid profile email',
	'state' => $_SESSION['oauth2_state'],
	'code_challenge' => $challenge,
	'code_challenge_method' => 'S256',
	'response_mode' => 'query',
	// Ensure the ID token can include the Entra "groups" claim, which we use
	// to map an optional admin Entra group object ID to the app's `admin`
	// role. Without this, Azure often omits `groups`, and all SSO users
	// become non-admin.
	'claims' => json_encode([
		'id_token' => [
			'groups' => [
				'essential' => true,
			],
		],
	], JSON_UNESCAPED_SLASHES),
], '', '&', PHP_QUERY_RFC3986);

$tenant = rawurlencode($settings['tenant_id']);
header('Location: https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/authorize?' . $params, true, 302);
exit;
