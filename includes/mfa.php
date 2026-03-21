<?php
declare(strict_types=1);

require_once __DIR__ . '/crypto-settings.php';

function auth_ensure_mfa_schema(PDO $pdo): void
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
	foreach (
		[
			['Email', "ALTER TABLE Users ADD COLUMN Email varchar(255) NULL DEFAULT NULL AFTER Username"],
			['MfaTotpSecret', "ALTER TABLE Users ADD COLUMN MfaTotpSecret mediumtext NULL DEFAULT NULL AFTER Email"],
			['MfaEmailEnabled', "ALTER TABLE Users ADD COLUMN MfaEmailEnabled tinyint(1) NOT NULL DEFAULT 0 AFTER MfaTotpSecret"],
			['MfaRequired', "ALTER TABLE Users ADD COLUMN MfaRequired tinyint(1) NOT NULL DEFAULT 0 AFTER MfaEmailEnabled"],
		] as $pair
	) {
		[$col, $ddl] = $pair;
		$chk->execute([$db, $col]);
		if ((int) $chk->fetchColumn() === 0) {
			try {
				$pdo->exec($ddl);
			} catch (PDOException $e) {
				// ignore race
			}
		}
	}

	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS UserWebAuthnCredentials (
			ID int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
			UserID int unsigned NOT NULL,
			CredentialId varbinary(512) NOT NULL,
			PublicKey mediumtext NOT NULL,
			SignCount bigint unsigned NOT NULL DEFAULT 0,
			FriendlyName varchar(100) NULL,
			CreatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			CONSTRAINT fk_webauthn_user FOREIGN KEY (UserID) REFERENCES Users(ID) ON DELETE CASCADE,
			UNIQUE KEY uq_webauthn_cred (CredentialId(255)),
			KEY idx_webauthn_user (UserID)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
	);

	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS MfaEmailChallenges (
			ID int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
			UserID int unsigned NOT NULL,
			CodeHash varchar(255) NOT NULL,
			ExpiresAt datetime NOT NULL,
			ConsumedAt datetime NULL,
			KEY idx_mfa_email_user_exp (UserID, ExpiresAt)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
	);

	$done = true;
}

/**
 * @return array<string, mixed>|null
 */
function auth_user_row_by_id(PDO $pdo, int $userId): ?array
{
	auth_ensure_mfa_schema($pdo);
	$stmt = $pdo->prepare(
		'SELECT u.ID, u.Username, u.Email, u.ExternalProvider, u.MfaTotpSecret, u.MfaEmailEnabled, u.MfaRequired,
			r.Name AS RoleName
		 FROM Users u
		 JOIN UserRoles ur ON ur.UserID = u.ID
		 JOIN Roles r ON r.ID = ur.RoleID
		 WHERE u.ID = :id
		 LIMIT 1'
	);
	$stmt->execute([':id' => $userId]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	return $row !== false ? $row : null;
}

function auth_webauthn_credential_count(PDO $pdo, int $userId): int
{
	auth_ensure_mfa_schema($pdo);
	$stmt = $pdo->prepare('SELECT COUNT(*) FROM UserWebAuthnCredentials WHERE UserID = :id');
	$stmt->execute([':id' => $userId]);

	return (int) $stmt->fetchColumn();
}

function auth_user_has_mfa_enrolled(PDO $pdo, int $userId, ?array $row = null): bool
{
	$row ??= auth_user_row_by_id($pdo, $userId);
	if ($row === null) {
		return false;
	}
	$totp = isset($row['MfaTotpSecret']) && is_string($row['MfaTotpSecret']) && trim($row['MfaTotpSecret']) !== '';
	if ($totp) {
		return true;
	}
	if (auth_webauthn_credential_count($pdo, $userId) > 0) {
		return true;
	}

	return false;
}

function auth_user_requires_mfa(PDO $pdo, int $userId, ?array $row = null): bool
{
	$row ??= auth_user_row_by_id($pdo, $userId);
	if ($row === null) {
		return false;
	}
	$isLocal = !isset($row['ExternalProvider']) || trim((string) $row['ExternalProvider']) === '';
	if ($isLocal && function_exists('auth_local_mfa_required') && auth_local_mfa_required()) {
		return true;
	}
	if (!empty($row['MfaRequired'])) {
		return true;
	}

	return auth_user_has_mfa_enrolled($pdo, $userId, $row);
}

/**
 * @return array{user_id:int,role:string,display_name:?string,username:?string,provider:?string,next:string,attempts:int}|null
 */
function auth_mfa_pending(): ?array
{
	if (!isset($_SESSION['mfa_pending']) || !is_array($_SESSION['mfa_pending'])) {
		return null;
	}
	$p = $_SESSION['mfa_pending'];
	$uid = isset($p['user_id']) ? (int) $p['user_id'] : 0;
	if ($uid < 1) {
		return null;
	}

	return [
		'user_id' => $uid,
		'role' => isset($p['role']) ? (string) $p['role'] : 'user',
		'display_name' => isset($p['display_name']) ? (string) $p['display_name'] : null,
		'username' => isset($p['username']) ? (string) $p['username'] : null,
		'provider' => isset($p['provider']) ? (string) $p['provider'] : null,
		'next' => isset($p['next']) ? auth_safe_next((string) $p['next']) : '/',
		'attempts' => isset($p['attempts']) ? (int) $p['attempts'] : 0,
	];
}

function auth_mfa_set_pending(
	int $userId,
	string $role,
	?string $displayName,
	?string $username,
	?string $provider,
	string $next
): void {
	$_SESSION['mfa_pending'] = [
		'user_id' => $userId,
		'role' => $role,
		'display_name' => $displayName,
		'username' => $username,
		'provider' => $provider,
		'next' => auth_safe_next($next),
		'attempts' => 0,
	];
}

function auth_mfa_clear_pending(): void
{
	unset($_SESSION['mfa_pending']);
}

function auth_mfa_bump_attempts(): void
{
	if (!isset($_SESSION['mfa_pending']) || !is_array($_SESSION['mfa_pending'])) {
		return;
	}
	$_SESSION['mfa_pending']['attempts'] = (int) ($_SESSION['mfa_pending']['attempts'] ?? 0) + 1;
}

function auth_mfa_attempts_exceeded(): bool
{
	$p = auth_mfa_pending();
	if ($p === null) {
		return true;
	}

	return $p['attempts'] >= 12;
}

function auth_totp_store_encrypt(string $secretBase32): string
{
	return auth_encrypt_secret_at_rest($secretBase32);
}

function auth_totp_store_decrypt(?string $stored): ?string
{
	if ($stored === null || trim($stored) === '') {
		return null;
	}

	return auth_decrypt_secret_at_rest($stored);
}

function auth_mfa_verify_totp(PDO $pdo, int $userId, string $code): bool
{
	auth_ensure_mfa_schema($pdo);
	$stmt = $pdo->prepare('SELECT MfaTotpSecret FROM Users WHERE ID = :id LIMIT 1');
	$stmt->execute([':id' => $userId]);
	$enc = $stmt->fetchColumn();
	if ($enc === false) {
		return false;
	}
	$secret = auth_totp_store_decrypt((string) $enc);
	if ($secret === null || $secret === '') {
		return false;
	}
	auth_load_otp_composer();
	if (!class_exists(\OTPHP\TOTP::class)) {
		return false;
	}
	$totp = \OTPHP\TOTP::createFromSecret($secret);
	// Allow paste with spaces/separators.
	$code = preg_replace('/\D+/', '', $code) ?? '';

	$leeway = min(10, $totp->getPeriod() - 1);
	return $totp->verify($code, null, $leeway);
}

function auth_load_otp_composer(): void
{
	static $loaded = false;
	if ($loaded) {
		return;
	}
	$autoload = __DIR__ . '/../vendor/autoload.php';
	if (is_file($autoload)) {
		require_once $autoload;
	}
	// Only mark as loaded once OTPhp is actually available.
	// This avoids a "first attempt during startup" scenario where vendor/autoload
	// may exist but the TOTP class isn't yet autoloadable.
	if (class_exists('OTPHP\\TOTP')) {
		$loaded = true;
	}
}

/**
 * Send a 6-digit verification code to an email address.
 * Stored in `MfaEmailChallenges` so we can reuse the existing schema.
 */
function auth_email_code_challenge_start(PDO $pdo, int $userId, string $toEmail): bool
{
	auth_ensure_mfa_schema($pdo);
	$toEmail = trim($toEmail);
	if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
		return false;
	}
	require_once __DIR__ . '/graph-mail.php';
	if (!graph_mail_is_configured()) {
		return false;
	}
	$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
	$hash = password_hash($code, PASSWORD_DEFAULT);
	$exp = (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
	$ins = $pdo->prepare(
		'INSERT INTO MfaEmailChallenges (UserID, CodeHash, ExpiresAt) VALUES (:uid, :h, :e)'
	);
	$ins->execute([':uid' => $userId, ':h' => $hash, ':e' => $exp]);
	$ok = graph_mail_send_mfa_code($toEmail, $code);
	if (function_exists('auth_log_email_event')) {
		$status = $ok ? 'sent' : 'failed';
		$detail = $ok ? 'code_sent' : (graph_mail_last_error() ?? 'send_failed');
		auth_log_email_event('verification_code', $toEmail, $status, $detail);
	}
	return $ok;
}

function auth_email_code_challenge_verify(PDO $pdo, int $userId, string $code): bool
{
	auth_ensure_mfa_schema($pdo);
	$code = preg_replace('/\D/', '', $code) ?? '';
	if (strlen($code) !== 6) {
		return false;
	}
	$stmt = $pdo->prepare(
		'SELECT ID, CodeHash FROM MfaEmailChallenges
		 WHERE UserID = :id AND ConsumedAt IS NULL AND ExpiresAt > NOW()
		 ORDER BY ID DESC LIMIT 5'
	);
	$stmt->execute([':id' => $userId]);
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $r) {
		$id = (int) ($r['ID'] ?? 0);
		$h = (string) ($r['CodeHash'] ?? '');
		if ($id < 1 || $h === '') {
			continue;
		}
		if (password_verify($code, $h)) {
			$pdo->prepare('UPDATE MfaEmailChallenges SET ConsumedAt = NOW() WHERE ID = :id')->execute([':id' => $id]);

			return true;
		}
	}

	return false;
}

// Backwards compatibility with older email MFA helper names (kept for now,
// but email MFA factor is no longer used by the sign-in flow).
function auth_mfa_email_challenge_start(PDO $pdo, int $userId): bool
{
	$row = auth_user_row_by_id($pdo, $userId);
	if ($row === null) {
		return false;
	}
	$email = isset($row['Email']) ? trim((string) $row['Email']) : '';
	return auth_email_code_challenge_start($pdo, $userId, $email);
}

function auth_mfa_email_challenge_verify(PDO $pdo, int $userId, string $code): bool
{
	return auth_email_code_challenge_verify($pdo, $userId, $code);
}
