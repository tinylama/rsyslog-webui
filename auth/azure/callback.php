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

$err = $_GET['error'] ?? '';
if (is_string($err) && $err !== '') {
	$desc = is_string($_GET['error_description'] ?? '') ? $_GET['error_description'] : '';
	header('Location: /login.php?err=' . rawurlencode('azure_' . $err . ($desc !== '' ? ': ' . $desc : '')), true, 302);
	exit;
}

$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';
if (!is_string($state) || $state === '' || !isset($_SESSION['oauth2_state']) || !hash_equals((string) $_SESSION['oauth2_state'], $state)) {
	header('Location: /login.php?err=' . rawurlencode('invalid_state'), true, 302);
	exit;
}
if (!is_string($code) || $code === '') {
	header('Location: /login.php?err=' . rawurlencode('missing_code'), true, 302);
	exit;
}

unset($_SESSION['oauth2_state']);
$verifier = $_SESSION['oauth2_code_verifier'] ?? '';
unset($_SESSION['oauth2_code_verifier']);
if (!is_string($verifier) || $verifier === '') {
	header('Location: /login.php?err=' . rawurlencode('missing_verifier'), true, 302);
	exit;
}

$settings = auth_azure_settings();
$redirectUri = auth_azure_effective_redirect_uri($settings);
$tenant = $settings['tenant_id'];

try {
	$token = auth_azure_http_post_form(
		'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
		[
			'client_id' => $settings['client_id'],
			'client_secret' => $settings['client_secret'],
			'code' => $code,
			'redirect_uri' => $redirectUri,
			'grant_type' => 'authorization_code',
			'code_verifier' => $verifier,
		]
	);
	$idToken = isset($token['id_token']) && is_string($token['id_token']) ? $token['id_token'] : '';
	if ($idToken === '') {
		throw new RuntimeException('no_id_token');
	}
	$decoded = auth_azure_verify_id_token($idToken, $settings);
	$role = auth_azure_role_from_claims($decoded, $settings);
	auth_bootstrap_once();
	$userId = auth_azure_provision_or_login($decoded, $role);
	$display = auth_azure_display_name($decoded);
	$pdo = auth_db();
	auth_ensure_mfa_schema($pdo);
	$emailClaim = '';
	foreach (['email', 'preferred_username', 'upn'] as $ek) {
		if (!empty($decoded->{$ek}) && is_string($decoded->{$ek})) {
			$emailClaim = trim($decoded->{$ek});
			break;
		}
	}
	if ($emailClaim !== '' && filter_var($emailClaim, FILTER_VALIDATE_EMAIL)) {
		$up = $pdo->prepare('UPDATE Users SET Email = COALESCE(NULLIF(TRIM(Email), \'\'), :e) WHERE ID = :id');
		$up->execute([':e' => $emailClaim, ':id' => $userId]);
	}
	$next = $_SESSION['oauth2_next'] ?? '/';
	unset($_SESSION['oauth2_next']);
	$next = auth_safe_next(is_string($next) ? $next : '/');
	if (auth_user_requires_mfa($pdo, $userId)) {
		auth_mfa_set_pending($userId, $role, $display, null, 'azure', $next);
		$_SESSION['csrf_login'] = bin2hex(random_bytes(32));
		header('Location: /mfa/challenge.php', true, 302);
		exit;
	}
	auth_establish_session($userId, $role, $display, 'azure');
	$_SESSION['csrf_login'] = bin2hex(random_bytes(32));
} catch (Throwable $e) {
	// Surface the underlying (sanitized) reason for faster diagnosis.
	$reason = $e->getMessage();
	if (!is_string($reason) || $reason === '') {
		$reason = 'unknown';
	}
	// Avoid dumping large payloads into the query string.
	$reason = mb_substr($reason, 0, 120);
	header('Location: /login.php?err=' . rawurlencode('azure_callback_failed:' . $reason), true, 302);
	exit;
}

header('Location: ' . $next, true, 302);
exit;
