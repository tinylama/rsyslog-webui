<?php
/**
 * DB-backed overrides for Entra / Graph settings (loaded after env config).
 */
declare(strict_types=1);

require_once __DIR__ . '/crypto-settings.php';

/** @var array<string, bool> */
$GLOBALS['__app_settings_secret_keys'] = [
	'azure_ad_client_secret' => true,
];

function auth_ensure_app_settings_schema(PDO $pdo): void
{
	static $done = false;
	if ($done) {
		return;
	}
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS AppSettings (
			SettingKey varchar(100) NOT NULL PRIMARY KEY,
			SettingValue mediumtext NOT NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
	);
	$done = true;
}

function app_settings_apply_row(string $key, string $value): void
{
	global $azure_ad_enabled, $azure_ad_tenant_id, $azure_ad_client_id, $azure_ad_client_secret;
	global $azure_ad_redirect_uri, $azure_ad_admin_group_id, $graph_mail_sender, $graph_mail_enabled;
	global $https_whitelist_enabled, $https_whitelist_cidrs, $local_mfa_required;

	$key = trim($key);
	if ($key === '') {
		return;
	}
	$secretKeys = $GLOBALS['__app_settings_secret_keys'] ?? [];
	if (is_array($secretKeys) && !empty($secretKeys[$key]) && $value !== '' && str_starts_with($value, 'enc:v1:')) {
		$dec = auth_decrypt_secret_at_rest($value);
		$value = $dec !== null ? $dec : '';
	}

	switch ($key) {
		case 'azure_ad_enabled':
			$azure_ad_enabled = ($value === '1' || strtolower($value) === 'true');
			break;
		case 'azure_ad_tenant_id':
			$azure_ad_tenant_id = $value;
			break;
		case 'azure_ad_client_id':
			$azure_ad_client_id = $value;
			break;
		case 'azure_ad_client_secret':
			$azure_ad_client_secret = $value;
			break;
		case 'azure_ad_redirect_uri':
			$azure_ad_redirect_uri = $value;
			break;
		case 'azure_ad_admin_group_id':
			$azure_ad_admin_group_id = $value;
			break;
		case 'graph_mail_sender':
			$graph_mail_sender = $value;
			break;
		case 'graph_mail_enabled':
			$graph_mail_enabled = $value;
			break;
		case 'https_whitelist_enabled':
			$https_whitelist_enabled = ($value === '1' || strtolower($value) === 'true');
			break;
		case 'https_whitelist_cidrs':
			$https_whitelist_cidrs = $value;
			break;
		case 'local_mfa_required':
			$local_mfa_required = ($value === '1' || strtolower($value) === 'true');
			break;
		default:
			break;
	}
}

function app_settings_refresh_from_db(): void
{
	if (!auth_is_enabled()) {
		return;
	}
	try {
		$pdo = auth_db();
		auth_ensure_app_settings_schema($pdo);
		$stmt = $pdo->query('SELECT SettingKey, SettingValue FROM AppSettings');
		if (!$stmt) {
			return;
		}
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$k = isset($row['SettingKey']) ? (string) $row['SettingKey'] : '';
			$v = isset($row['SettingValue']) ? (string) $row['SettingValue'] : '';
			app_settings_apply_row($k, $v);
		}
	} catch (Throwable $e) {
		// keep env defaults if DB unavailable
	}
}

function app_settings_save(PDO $pdo, string $key, string $value, bool $encrypt): void
{
	auth_ensure_app_settings_schema($pdo);
	$key = trim($key);
	if ($key === '') {
		return;
	}
	if ($encrypt && $value !== '') {
		$value = auth_encrypt_secret_at_rest($value);
	}
	$stmt = $pdo->prepare(
		'INSERT INTO AppSettings (SettingKey, SettingValue) VALUES (:k, :v)
		 ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
	);
	$stmt->execute([':k' => $key, ':v' => $value]);
}

function app_settings_get(PDO $pdo, string $key, bool $decryptSecret): string
{
	auth_ensure_app_settings_schema($pdo);
	$stmt = $pdo->prepare('SELECT SettingValue FROM AppSettings WHERE SettingKey = :k LIMIT 1');
	$stmt->execute([':k' => $key]);
	$v = $stmt->fetchColumn();
	if ($v === false) {
		return '';
	}
	$s = (string) $v;
	$secretKeys = $GLOBALS['__app_settings_secret_keys'] ?? [];
	if ($decryptSecret && is_array($secretKeys) && !empty($secretKeys[$key]) && $s !== '' && str_starts_with($s, 'enc:v1:')) {
		$d = auth_decrypt_secret_at_rest($s);

		return $d !== null ? $d : '';
	}

	return $s;
}
