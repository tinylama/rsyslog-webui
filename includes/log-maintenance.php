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

function log_maintenance_clear_logs(PDO $pdo): int
{
	$stmt = $pdo->prepare('DELETE FROM SystemEvents');
	$stmt->execute();
	return (int) $stmt->rowCount();
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
}
