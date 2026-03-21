<?php
// Centralized authentication/authorization helpers.
// Enforces login for UI and JSON APIs when `$auth_enabled` is true.

// Load `config.php` if present (production), otherwise fall back to
// `config-docker.php` for Docker/dev usage (config.php is gitignored).
$configFile = file_exists(__DIR__ . '/config.php')
	? __DIR__ . '/config.php'
	: __DIR__ . '/config-docker.php';
require_once $configFile;

// Session bootstrap (must happen before any session_* calls).
if (!headers_sent()) {
	$forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
	$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	if ($forwardedProto !== '') {
		// X-Forwarded-Proto can be a comma-separated list when multiple proxies exist.
		$secure = $secure || str_contains($forwardedProto, 'https');
	}
	session_set_cookie_params([
		'path' => '/',
		'secure' => $secure,
		'httponly' => true,
		'samesite' => 'Lax'
	]);
}

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

function auth_apply_security_headers(): void
{
	if (headers_sent()) {
		return;
	}
	header('X-Frame-Options: DENY');
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: strict-origin-when-cross-origin');
	header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

auth_apply_security_headers();

function auth_is_enabled(): bool
{
	global $auth_enabled;
	return (bool) $auth_enabled;
}

function auth_request_path(): string
{
	$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
	return is_string($path) && $path !== '' ? $path : '/';
}

function auth_request_user_agent(): string
{
	$ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
	if ($ua === '') {
		return '';
	}
	return mb_substr($ua, 0, 255);
}

/**
 * Return true when running inside Docker/containers.
 * Used to avoid blocking MFA enrollment in the local dev workflow.
 */
function auth_is_running_in_docker_dev(): bool
{
	if (file_exists('/.dockerenv')) {
		return true;
	}
	$cgroup = @file_get_contents('/proc/1/cgroup');
	if (is_string($cgroup) && preg_match('/docker|kubepods|containerd/i', $cgroup) === 1) {
		return true;
	}
	return false;
}

/**
 * Playwright E2E bootstrap: exchange a per-run token (filesystem, bind-mounted into the container)
 * for an admin session. Only active inside Docker dev when RSYSLOG_E2E_ENABLED=1.
 *
 * @see json/e2e-bootstrap.php
 * @see docs/testing.md
 */
function auth_e2e_bootstrap_feature_enabled(): bool
{
	if (!auth_is_running_in_docker_dev()) {
		return false;
	}
	$v = getenv('RSYSLOG_E2E_ENABLED');
	if ($v === false || $v === null || trim((string) $v) === '') {
		return false;
	}
	$s = strtolower(trim((string) $v));
	return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
}

function auth_e2e_bootstrap_token_path(): string
{
	$fromEnv = getenv('RSYSLOG_E2E_TOKEN_FILE');
	if (is_string($fromEnv) && trim($fromEnv) !== '') {
		return trim($fromEnv);
	}
	return __DIR__ . '/tests/e2e/.e2e-bootstrap-token';
}

function auth_e2e_read_expected_bootstrap_token(): string
{
	$path = auth_e2e_bootstrap_token_path();
	if (!is_readable($path)) {
		return '';
	}
	$raw = @file_get_contents($path);
	if (!is_string($raw)) {
		return '';
	}
	$t = trim($raw);
	// Minimum length so trivial/guessable tokens are rejected.
	if (strlen($t) < 32) {
		return '';
	}
	return $t;
}

/**
 * Login rate limiting (failed-attempt lockout). Set env RSYSLOG_LOGIN_RATE_LIMIT=0 in local dev compose
 * so Playwright runs are not blocked after repeated failed attempts; omit in production.
 */
function auth_login_rate_limit_enabled(): bool
{
	$v = getenv('RSYSLOG_LOGIN_RATE_LIMIT');
	if ($v === false || $v === null || trim((string) $v) === '') {
		return true;
	}
	$s = strtolower(trim((string) $v));
	return !($s === '0' || $s === 'false' || $s === 'off' || $s === 'no');
}

/**
 * Check for known placeholder/default secrets.
 * Gate only the most sensitive actions when running outside Docker.
 */
function auth_security_baseline_status(): array
{
	// In Docker/dev we allow placeholder secrets so local iteration and Playwright runs stay unblocked.
	if (auth_is_running_in_docker_dev()) {
		return ['safe' => true, 'detail' => ''];
	}

	global $session_secret, $admin_password;

	$sessionSecret = is_string($session_secret ?? null) ? (string) $session_secret : '';
	$adminPassword = is_string($admin_password ?? null) ? (string) $admin_password : '';

	$unsafeReasons = [];

	// These values exist in `config-template.php` (and are commonly reused in local setups).
	$defaultSessionSecrets = ['change-me', 'change-me-in-production', 'dev-session-secret-change-me'];
	$defaultAdminPasswords = ['change-me-immediately', 'change-me'];

	if ($sessionSecret !== '' && in_array($sessionSecret, $defaultSessionSecrets, true)) {
		$unsafeReasons[] = 'session_secret is still set to a default placeholder';
	}
	if ($adminPassword !== '' && in_array($adminPassword, $defaultAdminPasswords, true)) {
		$unsafeReasons[] = 'admin_password is still set to a default placeholder';
	}

	// If no reasons matched, consider it safe.
	if ($unsafeReasons === []) {
		return ['safe' => true, 'detail' => ''];
	}

	return [
		'safe' => false,
		'detail' => 'Security baseline warning: ' . implode('; ', $unsafeReasons) . '. Update secrets before enabling sensitive security enrollment (MFA enrollment / passkeys).',
	];
}

function auth_security_baseline_is_safe(): bool
{
	$st = auth_security_baseline_status();
	return (bool) ($st['safe'] ?? false);
}

/**
 * Parse/normalize whitelist CIDRs:
 * - accepts single IPs and CIDR ranges
 * - returns normalized "ip/prefix" (ip/32 or ip/128 when missing)
 */
function auth_normalize_whitelist_cidr(string $entry): ?string
{
	$e = trim($entry);
	if ($e === '') {
		return null;
	}
	$e = str_replace(' ', '', $e);
	$e = trim($e);

	$ip = $e;
	$prefix = '';
	if (str_contains($e, '/')) {
		[$ip, $prefix] = explode('/', $e, 2);
	}
	$ip = trim((string) $ip);

	if (!is_string($ip) || $ip === '') {
		return null;
	}

	$version = filter_var($ip, FILTER_VALIDATE_IP);
	if ($version === false) {
		return null;
	}

	$prefixInt = null;
	if ($prefix === '') {
		$prefixInt = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
	} else {
		if (!is_numeric($prefix)) {
			return null;
		}
		$prefixInt = (int) $prefix;
		// validate prefix for ipv4/ipv6
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			if ($prefixInt < 0 || $prefixInt > 32) return null;
		} else {
			if ($prefixInt < 0 || $prefixInt > 128) return null;
		}
	}

	// Normalize IP formatting.
	$ipNorm = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
		? strtolower($ip)
		: $ip;

	return $ipNorm . '/' . (string) $prefixInt;
}

function auth_ip_in_cidr(string $ip, string $cidr): bool
{
	$cidr = trim($cidr);
	if ($cidr === '') return false;
	if (!str_contains($cidr, '/')) return false;
	[$net, $prefixRaw] = explode('/', $cidr, 2);
	$prefix = is_numeric($prefixRaw) ? (int) $prefixRaw : -1;
	if ($prefix < 0) return false;

	$ip = trim($ip);
	$net = trim($net);

	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
		if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
		if ($prefix > 32) return false;
		$ipLong = ip2long($ip);
		$netLong = ip2long($net);
		if ($ipLong === false || $netLong === false) return false;

		$mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix));
		$mask = $mask & 0xFFFFFFFF;
		return (($ipLong & $mask) === ($netLong & $mask));
	}

	// IPv6
	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
		if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) return false;
		if ($prefix > 128) return false;
		$ipBin = inet_pton($ip);
		$netBin = inet_pton($net);
		if ($ipBin === false || $netBin === false) return false;
		$ipBytes = unpack('C*', $ipBin);
		$netBytes = unpack('C*', $netBin);
		if (!is_array($ipBytes) || !is_array($netBytes)) return false;

		$fullBytes = intdiv($prefix, 8);
		$remBits = $prefix % 8;

		for ($i = 1; $i <= 16; $i++) {
			if ($i <= $fullBytes) {
				if ($ipBytes[$i] !== $netBytes[$i]) return false;
			} elseif ($i === $fullBytes + 1 && $remBits > 0) {
				$mask = (0xFF << (8 - $remBits)) & 0xFF;
				if ( ($ipBytes[$i] & $mask) !== ($netBytes[$i] & $mask) ) return false;
			} else {
				// remaining bytes are irrelevant
				break;
			}
		}

		return true;
	}

	return false;
}

function auth_client_ip_for_whitelist(): string
{
	// If behind Cloudflare proxy, CF-Connecting-IP contains the real client IP.
	$cf = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? (string) $_SERVER['HTTP_CF_CONNECTING_IP'] : '';
	if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
		return trim($cf);
	}

	// Prefer X-Forwarded-For (Traefik sets it); take the left-most public-ish IP.
	$x = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
	if ($x !== '') {
		$parts = array_map('trim', explode(',', $x));
		foreach ($parts as $p) {
			if ($p !== '' && filter_var($p, FILTER_VALIDATE_IP) !== false) {
				return $p;
			}
		}
	}
	return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function auth_https_whitelist_enforce(): void
{
	global $https_whitelist_enabled, $https_whitelist_cidrs;
	// Defaults (when not set).
	$enabled = false;
	$listRaw = '';
	try {
		$enabled = isset($https_whitelist_enabled) ? (bool) $https_whitelist_enabled : false;
		$listRaw = isset($https_whitelist_cidrs) ? (string) $https_whitelist_cidrs : '';
	} catch (Throwable $e) {
		$enabled = false;
		$listRaw = '';
	}

	if (!$enabled) return;

	// Only enforce on HTTPS requests (determine original scheme behind proxy).
	$forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	if ($forwardedProto !== '') {
		$https = str_contains($forwardedProto, 'https');
	}
	if (!$https) return;

	$clientIp = auth_client_ip_for_whitelist();

	$entries = preg_split('/[\r\n,]+/', $listRaw);
	$cidrs = [];
	foreach ($entries as $e) {
		$n = auth_normalize_whitelist_cidr((string) $e);
		if ($n !== null) {
			$cidrs[] = $n;
		}
	}

	// If enabled but list is empty, block everything.
	if ($cidrs === []) {
		auth_log_access_decision('https_whitelist', 'blocked', 'https_whitelist', 'no_entries');
		http_response_code(403);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Forbidden';
		exit;
	}

	foreach ($cidrs as $cidr) {
		if (auth_ip_in_cidr($clientIp, $cidr)) {
			auth_log_access_decision('https_whitelist', 'allowed', 'https_whitelist', $cidr);
			return;
		}
	}

	auth_log_access_decision('https_whitelist', 'blocked', 'https_whitelist', 'ip_not_allowed');
	http_response_code(403);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Forbidden';
	exit;
}

/**
 * Prevent open redirects after login: only same-origin absolute paths.
 */
function auth_safe_next(?string $next): string
{
	if ($next === null || $next === '') {
		return '/';
	}
	$next = trim($next);
	if ($next === '' || ($next[0] ?? '') !== '/') {
		return '/';
	}
	if (str_starts_with($next, '//') || str_starts_with($next, '/\\')) {
		return '/';
	}
	if (str_contains($next, "\r") || str_contains($next, "\n")) {
		return '/';
	}
	// e.g. /javascript:evil or /http:evil
	if (preg_match('#^/[a-zA-Z][a-zA-Z0-9+.-]*:#', $next)) {
		return '/';
	}
	return $next;
}

function auth_db(): PDO
{
	global $mysql_server, $mysql_database, $mysql_user, $mysql_password;

	$pdo = new PDO(
		"mysql:host=$mysql_server;dbname=$mysql_database;charset=utf8",
		$mysql_user,
		$mysql_password,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		]
	);

	return $pdo;
}

function auth_local_mfa_required(): bool
{
	$v = $GLOBALS['local_mfa_required'] ?? false;
	if (is_bool($v)) {
		return $v;
	}
	if (is_string($v)) {
		$s = strtolower(trim($v));
		return $s === '1' || $s === 'true' || $s === 'yes';
	}
	return false;
}

/**
 * Generic audit event writer.
 *
 * @param array<string,mixed> $fields
 */
function auth_insert_audit_row(string $table, array $fields): void
{
	if (!auth_is_enabled()) {
		return;
	}
	if ($fields === []) {
		return;
	}
	try {
		$pdo = auth_db();
		auth_ensure_audit_schema($pdo);
		$cols = [];
		$vals = [];
		$params = [];
		foreach ($fields as $k => $v) {
			$key = trim((string) $k);
			if ($key === '') {
				continue;
			}
			$ph = ':' . $key;
			$cols[] = '`' . str_replace('`', '', $key) . '`';
			$vals[] = $ph;
			$params[$ph] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_SLASHES);
		}
		if ($cols === []) {
			return;
		}
		$sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);
	} catch (Throwable $e) {
		// Best-effort audit logging should never block request flow.
	}
}

function auth_log_signin_attempt(
	?int $userId,
	string $username,
	string $provider,
	string $outcome,
	string $reason = '',
	string $source = 'login'
): void {
	auth_insert_audit_row('SignInLogs', [
		'UserID' => $userId,
		'Username' => mb_substr($username, 0, 100),
		'Provider' => mb_substr($provider, 0, 20),
		'Outcome' => mb_substr($outcome, 0, 20),
		'Reason' => mb_substr($reason, 0, 255),
		'Source' => mb_substr($source, 0, 30),
		'ClientIP' => mb_substr(auth_client_ip_for_whitelist(), 0, 45),
		'UserAgent' => auth_request_user_agent(),
		'RoutePath' => mb_substr(auth_request_path(), 0, 255),
	]);
}

function auth_log_access_decision(string $area, string $decision, string $policy = '', string $detail = ''): void
{
	auth_insert_audit_row('AccessLogs', [
		'Area' => mb_substr($area, 0, 100),
		'RoutePath' => mb_substr(auth_request_path(), 0, 255),
		'ClientIP' => mb_substr(auth_client_ip_for_whitelist(), 0, 45),
		'Decision' => mb_substr($decision, 0, 20),
		'PolicySource' => mb_substr($policy, 0, 100),
		'Detail' => mb_substr($detail, 0, 255),
		'UserAgent' => auth_request_user_agent(),
		'UserID' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
	]);
}

function auth_log_email_event(string $eventType, string $recipient, string $status, string $providerMessage = ''): void
{
	auth_insert_audit_row('EmailLogs', [
		'EventType' => mb_substr($eventType, 0, 80),
		'Recipient' => mb_substr($recipient, 0, 255),
		'Status' => mb_substr($status, 0, 20),
		'ProviderMessage' => mb_substr($providerMessage, 0, 255),
		'ClientIP' => mb_substr(auth_client_ip_for_whitelist(), 0, 45),
		'UserAgent' => auth_request_user_agent(),
		'UserID' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
	]);
}

/**
 * Add ExternalProvider / ExternalSubject for Azure SSO (idempotent).
 */
function auth_ensure_users_external_columns(PDO $pdo): void
{
	static $done = false;
	if ($done) {
		return;
	}
	$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
	if (!is_string($db) || $db === '') {
		return;
	}
	$chk = $pdo->prepare(
		'SELECT COUNT(*) FROM information_schema.COLUMNS
		 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'Users\' AND COLUMN_NAME = ?'
	);
	$chk->execute([$db, 'ExternalProvider']);
	if ((int) $chk->fetchColumn() === 0) {
		$pdo->exec('ALTER TABLE Users ADD COLUMN ExternalProvider varchar(32) NULL DEFAULT NULL AFTER PasswordHash');
	}
	$chk->execute([$db, 'ExternalSubject']);
	if ((int) $chk->fetchColumn() === 0) {
		$pdo->exec('ALTER TABLE Users ADD COLUMN ExternalSubject varchar(36) NULL DEFAULT NULL AFTER ExternalProvider');
	}
	try {
		$pdo->exec('CREATE UNIQUE INDEX idx_users_external_subject ON Users (ExternalSubject)');
	} catch (PDOException $e) {
		// index already present
	}
	$done = true;
}

function auth_ensure_audit_schema(PDO $pdo): void
{
	static $done = false;
	if ($done) {
		return;
	}
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS SignInLogs (
			ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
			UserID int unsigned NULL,
			Username varchar(100) NOT NULL DEFAULT \'\',
			Provider varchar(20) NOT NULL DEFAULT \'local\',
			Outcome varchar(20) NOT NULL,
			Reason varchar(255) NOT NULL DEFAULT \'\',
			Source varchar(30) NOT NULL DEFAULT \'login\',
			ClientIP varchar(45) NOT NULL DEFAULT \'\',
			UserAgent varchar(255) NOT NULL DEFAULT \'\',
			RoutePath varchar(255) NOT NULL DEFAULT \'\',
			CreatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			KEY idx_signin_created (CreatedAt),
			KEY idx_signin_outcome (Outcome),
			KEY idx_signin_user (UserID),
			KEY idx_signin_provider (Provider)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS AccessLogs (
			ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
			UserID int unsigned NULL,
			Area varchar(100) NOT NULL DEFAULT \'\',
			RoutePath varchar(255) NOT NULL DEFAULT \'\',
			ClientIP varchar(45) NOT NULL DEFAULT \'\',
			Decision varchar(20) NOT NULL,
			PolicySource varchar(100) NOT NULL DEFAULT \'\',
			Detail varchar(255) NOT NULL DEFAULT \'\',
			UserAgent varchar(255) NOT NULL DEFAULT \'\',
			CreatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			KEY idx_access_created (CreatedAt),
			KEY idx_access_decision (Decision),
			KEY idx_access_area (Area)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS EmailLogs (
			ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
			UserID int unsigned NULL,
			EventType varchar(80) NOT NULL,
			Recipient varchar(255) NOT NULL DEFAULT \'\',
			Status varchar(20) NOT NULL,
			ProviderMessage varchar(255) NOT NULL DEFAULT \'\',
			ClientIP varchar(45) NOT NULL DEFAULT \'\',
			UserAgent varchar(255) NOT NULL DEFAULT \'\',
			CreatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			KEY idx_email_created (CreatedAt),
			KEY idx_email_status (Status),
			KEY idx_email_event (EventType)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS LoginRateLimit (
			RateKey varchar(190) NOT NULL PRIMARY KEY,
			Attempts int unsigned NOT NULL DEFAULT 0,
			WindowStartedAt datetime NOT NULL,
			BlockedUntil datetime NULL,
			UpdatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);
	$done = true;
}

function auth_login_rate_key(string $username, string $clientIp): string
{
	return mb_strtolower(trim($username)) . '|' . trim($clientIp);
}

/**
 * @return array{blocked:bool,retry_after:int}
 */
function auth_login_rate_limit_state(PDO $pdo, string $username, string $clientIp): array
{
	auth_ensure_audit_schema($pdo);
	$key = auth_login_rate_key($username, $clientIp);
	$stmt = $pdo->prepare('SELECT BlockedUntil FROM LoginRateLimit WHERE RateKey = :k LIMIT 1');
	$stmt->execute([':k' => $key]);
	$blockedUntil = $stmt->fetchColumn();
	if (!is_string($blockedUntil) || trim($blockedUntil) === '') {
		return ['blocked' => false, 'retry_after' => 0];
	}
	$ts = strtotime($blockedUntil);
	if ($ts === false) {
		return ['blocked' => false, 'retry_after' => 0];
	}
	$retry = $ts - time();
	if ($retry <= 0) {
		return ['blocked' => false, 'retry_after' => 0];
	}
	return ['blocked' => true, 'retry_after' => $retry];
}

function auth_login_rate_limit_record_failure(PDO $pdo, string $username, string $clientIp): void
{
	auth_ensure_audit_schema($pdo);
	$key = auth_login_rate_key($username, $clientIp);
	$stmt = $pdo->prepare('SELECT Attempts, WindowStartedAt FROM LoginRateLimit WHERE RateKey = :k LIMIT 1');
	$stmt->execute([':k' => $key]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	$now = new DateTimeImmutable('now');
	$windowStart = $now;
	$attempts = 1;
	if (is_array($row)) {
		$windowTs = strtotime((string) ($row['WindowStartedAt'] ?? ''));
		$withinWindow = $windowTs !== false && (time() - $windowTs) < 900;
		$attempts = $withinWindow ? ((int) ($row['Attempts'] ?? 0) + 1) : 1;
		$windowStart = $withinWindow ? (new DateTimeImmutable('@' . (string) $windowTs))->setTimezone(new DateTimeZone(date_default_timezone_get())) : $now;
	}

	$blockedUntil = null;
	if ($attempts >= 8) {
		$blockedUntil = $now->modify('+5 minutes')->format('Y-m-d H:i:s');
	}
	$ins = $pdo->prepare(
		'INSERT INTO LoginRateLimit (RateKey, Attempts, WindowStartedAt, BlockedUntil)
		 VALUES (:k, :a, :ws, :bu)
		 ON DUPLICATE KEY UPDATE Attempts = VALUES(Attempts), WindowStartedAt = VALUES(WindowStartedAt), BlockedUntil = VALUES(BlockedUntil)'
	);
	$ins->execute([
		':k' => $key,
		':a' => $attempts,
		':ws' => $windowStart->format('Y-m-d H:i:s'),
		':bu' => $blockedUntil,
	]);
}

function auth_login_rate_limit_clear(PDO $pdo, string $username, string $clientIp): void
{
	auth_ensure_audit_schema($pdo);
	$key = auth_login_rate_key($username, $clientIp);
	$pdo->prepare('DELETE FROM LoginRateLimit WHERE RateKey = :k')->execute([':k' => $key]);
}

function auth_bootstrap_once(): void
{
	if (!auth_is_enabled()) return;
	if (!isset($_SESSION['auth_bootstrap_done'])) {
		$pdo = auth_db();
		auth_ensure_app_settings_schema($pdo);
		auth_ensure_users_external_columns($pdo);
		auth_ensure_users_onboarding_column($pdo);
		auth_ensure_session_timeout_column($pdo);
		auth_ensure_mfa_schema($pdo);
		auth_ensure_audit_schema($pdo);

		// Ensure base roles exist.
		$pdo->exec("INSERT INTO Roles (Name) VALUES ('admin') ON DUPLICATE KEY UPDATE Name=Name");
		$pdo->exec("INSERT INTO Roles (Name) VALUES ('user') ON DUPLICATE KEY UPDATE Name=Name");

		$_SESSION['auth_bootstrap_done'] = true;
	}
}

function auth_ensure_users_onboarding_column(PDO $pdo): void
{
	static $done = false;
	if ($done) {
		return;
	}

	$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
	if (!is_string($db) || $db === '') {
		return;
	}

	$chk = $pdo->prepare(
		'SELECT COUNT(*) FROM information_schema.COLUMNS
		 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'Users\' AND COLUMN_NAME = ?'
	);
	$chk->execute([$db, 'UsersOnboardingShown']);
	if ((int) $chk->fetchColumn() === 0) {
		// Stored per-user so the tutorial only shows once per account.
		$pdo->exec('ALTER TABLE Users ADD COLUMN UsersOnboardingShown tinyint(1) NOT NULL DEFAULT 0 AFTER MfaRequired');
	}

	// Backwards compatibility if an earlier column name exists.
	$chk2 = $pdo->prepare(
		'SELECT COUNT(*) FROM information_schema.COLUMNS
		 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'Users\' AND COLUMN_NAME = ?'
	);
	$chk2->execute([$db, 'OnboardingShown']);
	if ((int) $chk2->fetchColumn() !== 0) {
		$pdo->exec('ALTER TABLE Users CHANGE COLUMN OnboardingShown UsersOnboardingShown tinyint(1) NOT NULL DEFAULT 0');
	}

	$done = true;
}

function auth_ensure_session_timeout_column(PDO $pdo): void
{
	static $done = false;
	if ($done) {
		return;
	}

	$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
	if (!is_string($db) || $db === '') {
		return;
	}

	$chk = $pdo->prepare(
		'SELECT COUNT(*) FROM information_schema.COLUMNS
		 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = \'Users\' AND COLUMN_NAME = ?'
	);
	$chk->execute([$db, 'SessionTimeoutSeconds']);

	// Default: 30 minutes (1800s). Use 0 for "no timeout".
	if ((int) $chk->fetchColumn() === 0) {
		$pdo->exec('ALTER TABLE Users ADD COLUMN SessionTimeoutSeconds int NOT NULL DEFAULT 1800 AFTER CreatedAt');
	}

	$done = true;
}

function auth_user_session_timeout_seconds(PDO $pdo, int $userId): int
{
	$stmt = $pdo->prepare('SELECT SessionTimeoutSeconds FROM Users WHERE ID = :id LIMIT 1');
	$stmt->execute([':id' => $userId]);
	$v = $stmt->fetchColumn();
	if ($v === false || $v === null) {
		return 1800;
	}
	$vInt = is_numeric($v) ? (int) $v : 1800;
	// Only allow 0 (no timeout) or positive values.
	if ($vInt < 0) {
		return 1800;
	}
	return $vInt;
}

function auth_user_has_completed_onboarding(PDO $pdo, int $userId): bool
{
	$stmt = $pdo->prepare('SELECT UsersOnboardingShown FROM Users WHERE ID = :id LIMIT 1');
	$stmt->execute([':id' => $userId]);
	$v = $stmt->fetchColumn();
	return (int) $v === 1;
}

function auth_current_user(): ?array
{
	auth_bootstrap_once();
	if (!auth_is_enabled()) return null;
	if (!isset($_SESSION['user_id'])) return null;

	$userId = (int) $_SESSION['user_id'];
	return [
		'id' => $userId,
		'role' => $_SESSION['user_role'] ?? 'user',
		'display_name' => isset($_SESSION['display_name']) ? (string) $_SESSION['display_name'] : null,
		'provider' => isset($_SESSION['auth_provider']) ? (string) $_SESSION['auth_provider'] : null,
	];
}

/**
 * Establish an authenticated session (local or SSO).
 */
function auth_establish_session(int $userId, string $role, ?string $displayName = null, ?string $provider = null): void
{
	session_regenerate_id(true);
	$_SESSION['user_id'] = $userId;
	$_SESSION['user_role'] = $role;
	$_SESSION['last_activity'] = time();
	try {
		$pdo = auth_db();
		$_SESSION['session_timeout_seconds'] = auth_user_session_timeout_seconds($pdo, $userId);
	} catch (Throwable $e) {
		$_SESSION['session_timeout_seconds'] = 1800;
	}
	if ($displayName !== null && $displayName !== '') {
		$_SESSION['display_name'] = $displayName;
	} else {
		unset($_SESSION['display_name']);
	}
	if ($provider !== null && $provider !== '') {
		$_SESSION['auth_provider'] = $provider;
	} else {
		unset($_SESSION['auth_provider']);
	}
}

function require_login(bool $json = false): void
{
	if (!auth_is_enabled()) return;

	auth_bootstrap_once();

	if (!isset($_SESSION['user_id'])) {
		auth_log_access_decision('auth', 'blocked', 'require_login', 'missing_session');
		if ($json) {
			http_response_code(401);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'unauthorized']);
			exit;
		}

		$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
		$path = is_string($path) && $path !== '' ? $path : '/';
		$next = urlencode(auth_safe_next($path));
		header("Location: login.php?next=$next");
		exit;
	}

	// Sliding session timeout based on last request activity.
	$uid = (int) $_SESSION['user_id'];
	$now = time();
	if (!isset($_SESSION['last_activity']) || !is_int($_SESSION['last_activity'])) {
		$_SESSION['last_activity'] = $now;
	}

	$timeoutSeconds = 0;
	if (isset($_SESSION['session_timeout_seconds']) && is_int($_SESSION['session_timeout_seconds'])) {
		$timeoutSeconds = $_SESSION['session_timeout_seconds'];
	} else {
		try {
			$pdo = auth_db();
			$timeoutSeconds = auth_user_session_timeout_seconds($pdo, $uid);
		} catch (Throwable $e) {
			$timeoutSeconds = 1800;
		}
	}

	if ($timeoutSeconds > 0 && ($now - (int) $_SESSION['last_activity']) > $timeoutSeconds) {
		auth_logout();
		if ($json) {
			http_response_code(401);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'session_timeout']);
			exit;
		}

		header('Location: /login.php?err=' . rawurlencode('session_timeout'), true, 302);
		exit;
	}

	// Update last-activity on successful auth check.
	$_SESSION['last_activity'] = $now;
}

/**
 * Number of users that currently have the admin role.
 */
function auth_admin_user_count(PDO $pdo): int
{
	$stmt = $pdo->query(
		"SELECT COUNT(DISTINCT ur.UserID) AS c
		 FROM UserRoles ur
		 INNER JOIN Roles r ON r.ID = ur.RoleID
		 WHERE r.Name = 'admin'"
	);
	$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

	return $row ? (int) $row['c'] : 0;
}

/**
 * True if this user id has the admin role (any admin row in UserRoles).
 */
function auth_user_is_admin(PDO $pdo, int $userId): bool
{
	$stmt = $pdo->prepare(
		"SELECT 1 FROM UserRoles ur
		 INNER JOIN Roles r ON r.ID = ur.RoleID
		 WHERE ur.UserID = :id AND r.Name = 'admin' LIMIT 1"
	);
	$stmt->execute([':id' => $userId]);

	return (bool) $stmt->fetchColumn();
}

/**
 * Require logged-in admin. When auth is disabled, admin UI is unavailable.
 */
function require_admin(bool $json = false): void
{
	if (!auth_is_enabled()) {
		if ($json) {
			http_response_code(503);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'auth_disabled', 'detail' => 'User management requires RSYSLOG_AUTH_ENABLED=1']);
			exit;
		}
		http_response_code(403);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'User management requires authentication to be enabled.';
		exit;
	}

	require_login($json);

	$me = auth_current_user();
	if ($me === null || ($me['role'] ?? '') !== 'admin') {
		auth_log_access_decision('admin', 'blocked', 'role_check', 'admin_role_required');
		if ($json) {
			http_response_code(403);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'forbidden', 'detail' => 'admin role required']);
			exit;
		}
		http_response_code(403);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Forbidden';
		exit;
	}
}

function auth_logout(): void
{
	auth_mfa_clear_pending();
	$_SESSION = [];
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
}

require_once __DIR__ . '/includes/app-settings.php';
require_once __DIR__ . '/includes/mfa.php';

if (auth_is_enabled()) {
	app_settings_refresh_from_db();
}

// Enforce HTTPS allowlist (optional).
// This must happen early so blocked requests can't reach pages or APIs.
auth_https_whitelist_enforce();

