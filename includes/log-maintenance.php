<?php
declare(strict_types=1);

/**
 * Return normalized retention settings.
 *
 * @return array{live_enabled: bool, mode: string, keep_days: int, max_rows: int}
 */
function log_maintenance_settings(PDO $pdo): array
{
	global $keep_logs_for_days;

	$defaultDays = (int) ($keep_logs_for_days ?? 90);
	if ($defaultDays < 1) {
		$defaultDays = 90;
	}

	$mode = trim((string) app_settings_get($pdo, 'logs_retention_mode', false));
	if (!in_array($mode, ['off', 'days', 'rows', 'both'], true)) {
		$mode = 'days';
	}

	$liveRaw = trim((string) app_settings_get($pdo, 'logs_retention_live_enabled', false));
	$liveEnabled = ($liveRaw === '1' || strtolower($liveRaw) === 'true');
	if ($liveRaw === '') {
		$liveEnabled = true;
	}

	$daysRaw = trim((string) app_settings_get($pdo, 'logs_retention_days', false));
	$days = $daysRaw === '' ? $defaultDays : (int) $daysRaw;
	if ($days < 1) {
		$days = 1;
	}
	if ($days > 3650) {
		$days = 3650;
	}

	$rowsRaw = trim((string) app_settings_get($pdo, 'logs_retention_max_rows', false));
	$maxRows = $rowsRaw === '' ? 2000 : (int) $rowsRaw;
	if ($maxRows < 100) {
		$maxRows = 100;
	}
	if ($maxRows > 5000000) {
		$maxRows = 5000000;
	}

	return [
		'live_enabled' => $liveEnabled,
		'mode' => $mode,
		'keep_days' => $days,
		'max_rows' => $maxRows,
	];
}

/**
 * Run retention and return deleted row counts.
 *
 * @param array{mode:string,keep_days:int,max_rows:int} $settings
 * @return array{deleted_days:int,deleted_rows:int,total_deleted:int}
 */
function log_maintenance_run(PDO $pdo, array $settings): array
{
	$mode = (string) ($settings['mode'] ?? 'off');
	$keepDays = (int) ($settings['keep_days'] ?? 90);
	$maxRows = (int) ($settings['max_rows'] ?? 2000);

	$deletedDays = 0;
	$deletedRows = 0;

	if ($mode === 'days' || $mode === 'both') {
		$stmtDays = $pdo->prepare('DELETE FROM SystemEvents WHERE COALESCE(ReceivedAt, DeviceReportedTime) < (NOW() - INTERVAL :days DAY)');
		$stmtDays->bindValue(':days', $keepDays, PDO::PARAM_INT);
		$stmtDays->execute();
		$deletedDays = (int) $stmtDays->rowCount();
	}

	if ($mode === 'rows' || $mode === 'both') {
		$sqlRows = 'DELETE FROM SystemEvents
			WHERE ID NOT IN (
				SELECT ID FROM (
					SELECT ID FROM SystemEvents ORDER BY ID DESC LIMIT ' . $maxRows . '
				) AS keepers
			)';
		$stmtRows = $pdo->prepare($sqlRows);
		$stmtRows->execute();
		$deletedRows = (int) $stmtRows->rowCount();
	}

	return [
		'deleted_days' => $deletedDays,
		'deleted_rows' => $deletedRows,
		'total_deleted' => $deletedDays + $deletedRows,
	];
}

/**
 * Refresh InnoDB table statistics so information_schema size figures track reality sooner after bulk deletes.
 *
 * @param list<string> $tables Base table names (alphanumeric + underscore only).
 */
function log_maintenance_analyze_tables(PDO $pdo, array $tables): void
{
	foreach ($tables as $raw) {
		$t = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $raw);
		if ($t === '') {
			continue;
		}
		try {
			$pdo->exec('ANALYZE TABLE `' . $t . '`');
		} catch (Throwable $e) {
			// Best-effort; ignore missing tables or permission issues.
		}
	}
}

/**
 * Refresh InnoDB data/index length stats after TRUNCATE/DELETE so information_schema matches on-disk allocation.
 */
function log_maintenance_refresh_table_stats(PDO $pdo, string $table): void
{
	$t = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
	if ($t === '') {
		return;
	}
	try {
		$pdo->exec('OPTIMIZE TABLE `' . $t . '`');
	} catch (Throwable $e) {
		log_maintenance_analyze_tables($pdo, [$t]);
	}
}

/**
 * Empty a table (TRUNCATE preferred). $table must be a known app log table name.
 */
function log_maintenance_truncate_table(PDO $pdo, string $table): void
{
	$allowed = ['SystemEvents', 'SignInLogs', 'AccessLogs', 'EmailLogs'];
	if (!in_array($table, $allowed, true)) {
		return;
	}
	try {
		$pdo->exec('TRUNCATE TABLE `' . $table . '`');
	} catch (Throwable $e) {
		try {
			$pdo->exec('DELETE FROM `' . $table . '`');
		} catch (Throwable $e2) {
			// ignore
		}
	}
	log_maintenance_refresh_table_stats($pdo, $table);
}

/**
 * Run live retention with a small cooldown to avoid heavy delete churn.
 */
function log_maintenance_maybe_run_live(PDO $pdo, int $cooldownSeconds = 30): void
{
	$settings = log_maintenance_settings($pdo);
	if (!$settings['live_enabled']) {
		return;
	}
	if ($settings['mode'] === 'off') {
		return;
	}

	$now = time();
	$lastRaw = trim((string) app_settings_get($pdo, 'logs_retention_last_run_ts', false));
	$lastRun = $lastRaw === '' ? 0 : (int) $lastRaw;
	if ($lastRun > 0 && ($now - $lastRun) < $cooldownSeconds) {
		return;
	}

	app_settings_save($pdo, 'logs_retention_last_run_ts', (string) $now, false);
	log_maintenance_run($pdo, $settings);
}

/**
 * Remove all syslog rows. TRUNCATE/DELETE is followed by OPTIMIZE/ANALYZE so information_schema
 * size columns drop on the next page load (InnoDB can otherwise keep stale DATA_LENGTH after TRUNCATE).
 */
function log_maintenance_clear_logs(PDO $pdo): int
{
	$countStmt = $pdo->query('SELECT COUNT(*) FROM SystemEvents');
	$before = $countStmt ? (int) $countStmt->fetchColumn() : 0;
	log_maintenance_truncate_table($pdo, 'SystemEvents');
	return $before;
}

/**
 * Clear selected admin audit tables (sign-in / access / email logs), then insert one AccessLogs row
 * describing the purge (so the action remains auditable).
 *
 * @param list<string> $tables Subset of SignInLogs, AccessLogs, EmailLogs
 */
function log_maintenance_clear_audit_tables(PDO $pdo, array $tables, ?int $actorUserId, string $actorName): void
{
	$allowed = ['SignInLogs', 'AccessLogs', 'EmailLogs'];
	$toClear = [];
	foreach ($tables as $raw) {
		$t = (string) $raw;
		if (in_array($t, $allowed, true)) {
			$toClear[$t] = true;
		}
	}
	$toClear = array_keys($toClear);
	if ($toClear === []) {
		return;
	}
	auth_ensure_audit_schema($pdo);
	sort($toClear);
	foreach ($toClear as $t) {
		log_maintenance_truncate_table($pdo, $t);
	}
	$actorLabel = trim($actorName) !== '' ? trim($actorName) : 'admin';
	$detail = 'Audit log purge: ' . implode(', ', $toClear) . ' by ' . $actorLabel;
	$detail = mb_substr($detail, 0, 255);
	$path = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/maintenance.php');
	$ip = function_exists('auth_client_ip_for_whitelist') ? auth_client_ip_for_whitelist() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
	$ua = function_exists('auth_request_user_agent') ? auth_request_user_agent() : (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
	try {
		$stmt = $pdo->prepare(
			'INSERT INTO AccessLogs (UserID, Area, RoutePath, ClientIP, Decision, PolicySource, Detail, UserAgent)
			 VALUES (:uid, :area, :path, :ip, :decision, :policy, :detail, :ua)'
		);
		$stmt->execute([
			':uid' => $actorUserId,
			':area' => 'maintenance',
			':path' => mb_substr($path, 0, 255),
			':ip' => mb_substr($ip, 0, 45),
			':decision' => 'allowed',
			':policy' => 'admin_ui',
			':detail' => $detail,
			':ua' => mb_substr($ua, 0, 255),
		]);
	} catch (Throwable $e) {
		// Best-effort; purge already completed.
	}
	log_maintenance_refresh_table_stats($pdo, 'AccessLogs');
}

/**
 * Clears all app data rows while keeping schema intact.
 */
function log_maintenance_clear_database_data(PDO $pdo): void
{
	$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
	try {
		$tables = [
			'MfaEmailChallenges',
			'UserWebAuthnCredentials',
			'UserRoles',
			'Users',
			'Roles',
			'AppSettings',
			'SystemEvents',
		];
		foreach ($tables as $table) {
			try {
				$pdo->exec('DELETE FROM ' . $table);
			} catch (Throwable $e) {
				// Table may not exist in older installs.
			}
		}
	} finally {
		$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
	}

	log_maintenance_analyze_tables($pdo, [
		'SystemEvents',
		'Users',
		'Roles',
		'UserRoles',
		'AppSettings',
		'MfaEmailChallenges',
		'UserWebAuthnCredentials',
	]);
}
