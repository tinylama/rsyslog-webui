<?php
/**
 * Microsoft Entra ID (Azure AD) OIDC authorization-code + PKCE.
 */

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

function auth_azure_load_composer(): void
{
	static $loaded = false;
	if ($loaded) {
		return;
	}
	$loaded = true;
	$autoload = __DIR__ . '/../vendor/autoload.php';
	if (is_file($autoload)) {
		require_once $autoload;
	}
}

function auth_azure_enabled(): bool
{
	$v = $GLOBALS['azure_ad_enabled'] ?? false;
	if (is_bool($v)) {
		return $v;
	}
	if ($v === '1') {
		return true;
	}
	if (is_string($v) && strtolower($v) === 'true') {
		return true;
	}

	return false;
}

/**
 * @return array{tenant_id:string,client_id:string,client_secret:string,redirect_uri:string,admin_group_id:string}
 */
function auth_azure_settings(): array
{
	return [
		'tenant_id' => trim((string) ($GLOBALS['azure_ad_tenant_id'] ?? '')),
		'client_id' => trim((string) ($GLOBALS['azure_ad_client_id'] ?? '')),
		'client_secret' => (string) ($GLOBALS['azure_ad_client_secret'] ?? ''),
		'redirect_uri' => trim((string) ($GLOBALS['azure_ad_redirect_uri'] ?? '')),
		'admin_group_id' => trim((string) ($GLOBALS['azure_ad_admin_group_id'] ?? '')),
	];
}

function auth_azure_is_configured(): bool
{
	$s = auth_azure_settings();

	return $s['tenant_id'] !== '' && $s['client_id'] !== '' && $s['client_secret'] !== ''
		&& auth_azure_effective_redirect_uri($s) !== '';
}

function auth_azure_effective_redirect_uri(array $settings): string
{
	if ($settings['redirect_uri'] !== '') {
		// If an explicit redirect URI was set as http:// but the incoming request
		// is actually HTTPS (e.g. behind a reverse-proxy like Traefik), upgrade it
		// to avoid Entra redirect URI mismatch errors.
		$forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
		$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		if ($forwardedProto !== '') {
			// X-Forwarded-Proto can be a comma-separated list (proxy chains).
			$https = str_contains($forwardedProto, 'https');
		}

		$ri = (string) $settings['redirect_uri'];
		if ($https && str_starts_with($ri, 'http://')) {
			return 'https://' . substr($ri, 7);
		}
		return $ri;
	}

	$forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
	$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
	if ($forwardedProto !== '') {
		// X-Forwarded-Proto can be a comma-separated list (proxy chains).
		$https = str_contains($forwardedProto, 'https');
	}
	$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$scheme = $https ? 'https' : 'http';

	return $scheme . '://' . $host . '/auth/azure/callback.php';
}

function auth_azure_http_post_form(string $url, array $fields): array
{
	$body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
	$ctx = stream_context_create([
		'http' => [
			'method' => 'POST',
			'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n",
			'content' => $body,
			'timeout' => 20,
			'ignore_errors' => true,
		],
	]);
	$raw = @file_get_contents($url, false, $ctx);
	if ($raw === false) {
		throw new RuntimeException('token_http_failed');
	}
	$json = json_decode($raw, true);
	if (!is_array($json)) {
		return [];
	}
	if (!empty($json['error'])) {
		$err = is_string($json['error'] ?? null) ? (string) $json['error'] : 'unknown_error';
		$desc = is_string($json['error_description'] ?? null) ? (string) $json['error_description'] : '';
		// Avoid dumping huge strings into the URL/UI; keep it concise.
		$desc = $desc !== '' ? mb_substr(trim($desc), 0, 160) : '';
		$msg = 'token_error:' . $err . ($desc !== '' ? (': ' . $desc) : '');
		throw new RuntimeException($msg);
	}

	return $json;
}

/**
 * @return stdClass JWT payload
 */
function auth_azure_verify_id_token(string $idToken, array $settings): stdClass
{
	auth_azure_load_composer();
	if (!class_exists(JWT::class)) {
		throw new RuntimeException('jwt_library_missing');
	}
	$tenant = $settings['tenant_id'];
	$jwksUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/discovery/v2.0/keys';
	$jwksRaw = @file_get_contents($jwksUrl);
	if ($jwksRaw === false) {
		throw new RuntimeException('jwks_fetch_failed');
	}
	$jwks = json_decode($jwksRaw, true);
	if (!is_array($jwks)) {
		throw new RuntimeException('jwks_invalid');
	}
	$keySet = JWK::parseKeySet($jwks, 'RS256');
	JWT::$leeway = 120;
	$decoded = JWT::decode($idToken, $keySet);

	$clientId = $settings['client_id'];
	$audOk = false;
	if (isset($decoded->aud)) {
		if (is_string($decoded->aud)) {
			$audOk = $decoded->aud === $clientId;
		} elseif (is_array($decoded->aud)) {
			$audOk = in_array($clientId, $decoded->aud, true);
		}
	}
	if (!$audOk) {
		throw new RuntimeException('jwt_aud_invalid');
	}
	$iss = (string) ($decoded->iss ?? '');
	if ($iss === '' || !str_starts_with($iss, 'https://login.microsoftonline.com/') || !str_ends_with($iss, '/v2.0')) {
		throw new RuntimeException('jwt_iss_invalid');
	}

	return $decoded;
}

function auth_azure_role_from_claims(stdClass $decoded, array $settings): string
{
	$adminGid = strtolower(trim((string) ($settings['admin_group_id'] ?? '')));
	if ($adminGid === '') {
		return 'user';
	}
	$candidates = [];
	$groups = $decoded->groups ?? null;
	if (is_array($groups)) {
		$candidates = $groups;
	} elseif (is_string($groups) && $groups !== '') {
		$candidates = [$groups];
	}

	// Some tenants may emit app roles rather than group IDs.
	$roles = $decoded->roles ?? null;
	if (is_array($roles)) {
		// Treat roles as string candidates too.
		$candidates = array_merge($candidates, $roles);
	}

	$normalized = [];
	foreach ($candidates as $g) {
		if (!is_string($g) && !is_numeric($g)) {
			continue;
		}
		$normalized[] = strtolower(trim((string) $g));
	}

	return in_array($adminGid, $normalized, true) ? 'admin' : 'user';
}

function auth_azure_display_name(stdClass $decoded): string
{
	foreach (['preferred_username', 'email', 'upn', 'name'] as $k) {
		if (!empty($decoded->{$k}) && is_string($decoded->{$k})) {
			return $decoded->{$k};
		}
	}
	$sub = (string) ($decoded->sub ?? 'user');

	return 'user_' . substr($sub, 0, 12);
}

/**
 * @throws RuntimeException on failure
 */
function auth_azure_provision_or_login(stdClass $decoded, string $role): int
{
	$pdo = auth_db();
	auth_ensure_users_external_columns($pdo);

	$oid = (string) ($decoded->oid ?? $decoded->sub ?? '');
	if ($oid === '') {
		throw new RuntimeException('missing_subject');
	}
	$display = auth_azure_display_name($decoded);
	$uname = $display;
	if (strlen($uname) > 95) {
		$uname = substr($uname, 0, 95);
	}

	$stmt = $pdo->prepare('SELECT u.ID FROM Users u WHERE u.ExternalProvider = :p AND u.ExternalSubject = :s LIMIT 1');
	$stmt->execute([':p' => 'azure', ':s' => $oid]);
	$existing = $stmt->fetchColumn();
	if ($existing !== false) {
		$uid = (int) $existing;
		// Keep DB roles in sync with the latest role computed from claims.
		// This is important if group membership changes after the user was first provisioned.
		$targetRole = $role === 'admin' ? 'admin' : 'user';

		$pdo->beginTransaction();
		try {
			$stmtAdmin = $pdo->prepare('SELECT ID FROM Roles WHERE Name = \'admin\' LIMIT 1');
			$stmtAdmin->execute();
			$ridAdmin = (int) $stmtAdmin->fetchColumn();

			$stmtUser = $pdo->prepare('SELECT ID FROM Roles WHERE Name = \'user\' LIMIT 1');
			$stmtUser->execute();
			$ridUser = (int) $stmtUser->fetchColumn();

			if ($ridAdmin < 1 || $ridUser < 1) {
				$pdo->rollBack();
				return $uid;
			}

			$targetRid = $targetRole === 'admin' ? $ridAdmin : $ridUser;

			// Remove both mappings then re-add the correct one.
			$pdo->prepare('DELETE FROM UserRoles WHERE UserID = :uid AND RoleID IN (:ra, :ru)')
				->execute([':uid' => $uid, ':ra' => $ridAdmin, ':ru' => $ridUser]);

			$pdo->prepare('INSERT IGNORE INTO UserRoles (UserID, RoleID) VALUES (:uid, :rid)')
				->execute([':uid' => $uid, ':rid' => $targetRid]);

			$pdo->commit();
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
		}

		return $uid;
	}

	$suffix = 0;
	$tryName = $uname;
	while ($suffix < 50) {
		$check = $pdo->prepare('SELECT 1 FROM Users WHERE Username = :u LIMIT 1');
		$check->execute([':u' => $tryName]);
		if (!$check->fetchColumn()) {
			break;
		}
		$suffix++;
		$tryName = $uname . '_' . $suffix;
	}
	if (strlen($tryName) > 100) {
		$tryName = 'azure_' . substr(hash('sha256', $oid), 0, 20);
	}

	$dummyHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
	$pdo->beginTransaction();
	try {
		$ins = $pdo->prepare(
			'INSERT INTO Users (Username, PasswordHash, ExternalProvider, ExternalSubject) VALUES (:u, :h, :p, :s)'
		);
		$ins->execute([':u' => $tryName, ':h' => $dummyHash, ':p' => 'azure', ':s' => $oid]);
		$uid = (int) $pdo->lastInsertId();
		$stmtRole = $pdo->prepare('SELECT ID FROM Roles WHERE Name = :r LIMIT 1');
		$stmtRole->execute([':r' => $role]);
		$roleId = (int) $stmtRole->fetchColumn();
		if ($roleId < 1) {
			throw new RuntimeException('role_missing');
		}
		$pdo->prepare('INSERT INTO UserRoles (UserID, RoleID) VALUES (:uid, :rid)')->execute([
			':uid' => $uid,
			':rid' => $roleId,
		]);
		$pdo->commit();

		return $uid;
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		throw new RuntimeException('provision_failed', 0, $e);
	}
}
