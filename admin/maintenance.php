<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/log-maintenance.php';

require_admin();

$me = auth_current_user();
$displayName = is_array($me) ? (string) ($me['display_name'] ?? '') : '';
$navAdminActive = 'maintenance';

if (!isset($_SESSION['csrf_admin_maintenance']) || !is_string($_SESSION['csrf_admin_maintenance']) || strlen($_SESSION['csrf_admin_maintenance']) < 32) {
	$_SESSION['csrf_admin_maintenance'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_admin_maintenance'];

$pdo = auth_db();
auth_ensure_app_settings_schema($pdo);

$error = '';
$success = '';
$lastRunResult = null;

$settings = log_maintenance_settings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedCsrf = $_POST['csrf_token'] ?? '';
	if (!is_string($postedCsrf) || !hash_equals($csrfToken, $postedCsrf)) {
		$error = 'Invalid session. Refresh and try again.';
	} else {
		$action = (string) ($_POST['action'] ?? '');
		if ($action === 'save_policy') {
			$mode = (string) ($_POST['retention_mode'] ?? 'days');
			$liveEnabled = isset($_POST['live_enabled']) && $_POST['live_enabled'] === '1';
			$days = (int) ($_POST['keep_days'] ?? 90);
			$maxRows = (int) ($_POST['max_rows'] ?? 2000);

			if (!in_array($mode, ['off', 'days', 'rows', 'both'], true)) {
				$error = 'Invalid retention mode.';
			} elseif ($days < 1 || $days > 3650) {
				$error = 'Days must be between 1 and 3650.';
			} elseif ($maxRows < 100 || $maxRows > 5000000) {
				$error = 'Max rows must be between 100 and 5,000,000.';
			} else {
				app_settings_save($pdo, 'logs_retention_mode', $mode, false);
				app_settings_save($pdo, 'logs_retention_live_enabled', $liveEnabled ? '1' : '0', false);
				app_settings_save($pdo, 'logs_retention_days', (string) $days, false);
				app_settings_save($pdo, 'logs_retention_max_rows', (string) $maxRows, false);
				$success = 'Maintenance policy saved.';
			}
		} elseif ($action === 'run_now') {
			$run = log_maintenance_run($pdo, $settings);
			$lastRunResult = $run;
			app_settings_save($pdo, 'logs_retention_last_run_ts', (string) time(), false);
			$success = 'Retention run completed.';
		} elseif ($action === 'clear_logs') {
			$deleted = log_maintenance_clear_logs($pdo);
			$success = 'Logs cleared. Rows removed: ' . $deleted . '.';
		} elseif ($action === 'clear_database_data') {
			$confirm = trim((string) ($_POST['confirm_text'] ?? ''));
			if ($confirm !== 'CLEAR DATABASE') {
				$error = 'Type CLEAR DATABASE to confirm.';
			} else {
				log_maintenance_clear_database_data($pdo);
				unset($_SESSION['auth_bootstrap_done']);
				auth_bootstrap_once();
				$success = 'Database data cleared (schema kept).';
			}
		} else {
			$error = 'Unknown action.';
		}
	}
	$settings = log_maintenance_settings($pdo);
}

$statsStmt = $pdo->query('SELECT COUNT(*) AS c, MIN(ReceivedAt) AS oldest, MAX(ReceivedAt) AS newest FROM SystemEvents');
$stats = $statsStmt ? $statsStmt->fetch(PDO::FETCH_ASSOC) : ['c' => 0, 'oldest' => null, 'newest' => null];
$rowCount = (int) ($stats['c'] ?? 0);
$oldest = isset($stats['oldest']) ? (string) $stats['oldest'] : '';
$newest = isset($stats['newest']) ? (string) $stats['newest'] : '';
$siteTitle = htmlspecialchars($site_name ?? 'System Logs', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Maintenance - <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="../css/custom.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
</head>
<body>
<div class="app-shell py-3 py-md-4">
	<?php include __DIR__ . '/../includes/partials/navbar-admin.php'; ?>

	<div class="row g-3 mb-4">
		<div class="col-12 col-md-8">
			<h1 class="h3 mb-1"><i class="bi bi-tools me-2" aria-hidden="true"></i>Maintenance</h1>
			<p class="text-muted mb-0 small">Control live log retention and clear log data safely from the admin UI.</p>
		</div>
		<div class="col-12 col-md-4">
			<div class="card border-0 bg-body-secondary h-100">
				<div class="card-body d-flex align-items-center justify-content-between">
					<span class="text-muted small">Current logs</span>
					<span class="fs-4 fw-semibold"><?php echo $rowCount; ?></span>
				</div>
			</div>
		</div>
	</div>

	<?php if ($error !== ''): ?>
		<div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>
	<?php if ($success !== ''): ?>
		<div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-4">
			<h2 class="h5 mb-3">Live retention policy</h2>
			<form method="post" class="row g-3">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
				<input type="hidden" name="action" value="save_policy">
				<div class="col-12">
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" id="live_enabled" name="live_enabled" value="1" <?php echo $settings['live_enabled'] ? 'checked' : ''; ?>>
						<label class="form-check-label" for="live_enabled">Enable live retention on API requests</label>
					</div>
					<div class="form-text">When enabled, cleanup runs continuously while users view logs.</div>
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label" for="retention_mode">Retention mode</label>
					<select class="form-select" id="retention_mode" name="retention_mode">
						<option value="off" <?php echo $settings['mode'] === 'off' ? 'selected' : ''; ?>>Off</option>
						<option value="days" <?php echo $settings['mode'] === 'days' ? 'selected' : ''; ?>>By days</option>
						<option value="rows" <?php echo $settings['mode'] === 'rows' ? 'selected' : ''; ?>>By max rows</option>
						<option value="both" <?php echo $settings['mode'] === 'both' ? 'selected' : ''; ?>>Days + max rows</option>
					</select>
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label" for="keep_days">Keep days</label>
					<input class="form-control" type="number" id="keep_days" name="keep_days" min="1" max="3650" value="<?php echo (int) $settings['keep_days']; ?>">
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label" for="max_rows">Max rows to keep</label>
					<input class="form-control" type="number" id="max_rows" name="max_rows" min="100" max="5000000" value="<?php echo (int) $settings['max_rows']; ?>">
				</div>
				<div class="col-12 d-flex flex-wrap gap-2">
					<button type="submit" class="btn btn-primary">Save policy</button>
					<button type="submit" class="btn btn-outline-secondary" name="action" value="run_now">Run cleanup now</button>
				</div>
			</form>
			<?php if (is_array($lastRunResult)): ?>
				<div class="mt-3 small text-muted">
					Last run deleted <?php echo (int) $lastRunResult['total_deleted']; ?> rows
					(days: <?php echo (int) $lastRunResult['deleted_days']; ?>, rows-limit: <?php echo (int) $lastRunResult['deleted_rows']; ?>).
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-4">
			<h2 class="h5 mb-3">Log data actions</h2>
			<div class="small text-muted mb-3">
				Oldest log: <code><?php echo htmlspecialchars($oldest !== '' ? $oldest : '-', ENT_QUOTES, 'UTF-8'); ?></code>,
				Newest log: <code><?php echo htmlspecialchars($newest !== '' ? $newest : '-', ENT_QUOTES, 'UTF-8'); ?></code>
			</div>
			<form method="post" class="d-inline-block me-2 mb-2" onsubmit="return confirm('Clear all logs from SystemEvents?');">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
				<input type="hidden" name="action" value="clear_logs">
				<button type="submit" class="btn btn-outline-danger">Clear logs</button>
			</form>
		</div>
	</div>

	<div class="card border-0 shadow-sm">
		<div class="card-body p-4">
			<h2 class="h5 text-danger mb-3">Danger zone</h2>
			<p class="small text-muted">Clears all data rows (users, roles, settings, MFA records, passkeys, logs). Schema remains intact.</p>
			<form method="post" class="row g-2 align-items-end" onsubmit="return confirm('This clears all app data rows. Continue?');">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
				<input type="hidden" name="action" value="clear_database_data">
				<div class="col-12 col-md-5">
					<label class="form-label small" for="confirm_text">Type <code>CLEAR DATABASE</code> to confirm</label>
					<input class="form-control" id="confirm_text" name="confirm_text" autocomplete="off" required>
				</div>
				<div class="col-12 col-md-3 d-grid">
					<button type="submit" class="btn btn-danger">Clear database data</button>
				</div>
			</form>
		</div>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
