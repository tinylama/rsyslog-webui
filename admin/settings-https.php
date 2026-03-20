<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';

require_admin();

$me = auth_current_user();
$myId = (int) ($me['id'] ?? 0);

$navAdminActive = 'https';

if (!isset($_SESSION['csrf_admin_https']) || !is_string($_SESSION['csrf_admin_https']) || strlen($_SESSION['csrf_admin_https']) < 32) {
	$_SESSION['csrf_admin_https'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_admin_https'];

$pdo = auth_db();

// Loaded from env defaults + AppSettings DB overrides.
$enabled = isset($https_whitelist_enabled) ? (bool) $https_whitelist_enabled : false;
$listRaw = isset($https_whitelist_cidrs) ? (string) $https_whitelist_cidrs : '';

$error = '';
$success = '';

/**
 * @return array<int, string> Normalized CIDR list
 */
function parse_https_whitelist_entries(string $raw): array
{
	$items = preg_split('/[\r\n,]+/', $raw);
	$out = [];
	$seen = [];
	foreach ($items as $it) {
		$norm = auth_normalize_whitelist_cidr((string) $it);
		if ($norm === null) continue;
		if (isset($seen[$norm])) continue;
		$seen[$norm] = true;
		$out[] = $norm;
	}
	return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedCsrf = $_POST['csrf_token'] ?? '';
	if (!is_string($postedCsrf) || !hash_equals($csrfToken, $postedCsrf)) {
		$error = 'Invalid session. Refresh and try again.';
	} else {
		$action = (string) ($_POST['action'] ?? '');

		$cidrs = parse_https_whitelist_entries($listRaw);

		if ($action === 'set_enabled') {
			$enabled = isset($_POST['enabled']) ? true : false;
			app_settings_save($pdo, 'https_whitelist_enabled', $enabled ? '1' : '0', false);
			$success = $enabled ? 'HTTPS whitelist enabled.' : 'HTTPS whitelist disabled.';
		} elseif ($action === 'add') {
			$input = trim((string) ($_POST['cidrs_input'] ?? ''));
			if ($input === '') {
				$error = 'Enter at least one IP or CIDR.';
			} else {
				$parts = preg_split('/[\r\n,]+/', $input);
				$added = 0;
				foreach ($parts as $p) {
					$norm = auth_normalize_whitelist_cidr((string) $p);
					if ($norm === null) {
						$error = 'Invalid IP/CIDR: ' . htmlspecialchars(trim((string) $p), ENT_QUOTES, 'UTF-8');
						break;
					}
					if (!in_array($norm, $cidrs, true)) {
						$cidrs[] = $norm;
						$added++;
					}
					if (count($cidrs) > 200) {
						$error = 'Too many entries (max 200).';
						break;
					}
				}

				if ($error === '') {
					$listRaw = implode("\n", $cidrs);
					app_settings_save($pdo, 'https_whitelist_cidrs', $listRaw, false);
					$success = $added > 0 ? 'Entry added.' : 'Entry already exists.';
				}
			}
		} elseif ($action === 'remove') {
			$target = trim((string) ($_POST['cidr'] ?? ''));
			if ($target === '') {
				$error = 'Invalid entry.';
			} else {
				$normTarget = auth_normalize_whitelist_cidr($target);
				if ($normTarget === null) {
					$error = 'Invalid entry.';
				} else {
					$cidrs = array_values(array_filter($cidrs, fn($x) => $x !== $normTarget));
					$listRaw = implode("\n", $cidrs);
					app_settings_save($pdo, 'https_whitelist_cidrs', $listRaw, false);
					$success = 'Entry removed.';
				}
			}
		} else {
			$error = 'Unknown action.';
		}

		// Refresh local copies after save.
		// (DB overrides take effect immediately for subsequent requests.)
		// Note: app_settings_refresh_from_db() is already called by auth.php,
		// but reloading avoids stale values in this request.
		app_settings_refresh_from_db();
		$enabled = isset($https_whitelist_enabled) ? (bool) $https_whitelist_enabled : false;
		$listRaw = isset($https_whitelist_cidrs) ? (string) $https_whitelist_cidrs : $listRaw;
	}
}

$cidrs = parse_https_whitelist_entries($listRaw);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>HTTPS whitelist — <?php echo htmlspecialchars($site_name ?? 'System Logs', ENT_QUOTES, 'UTF-8'); ?></title>
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
			<h1 class="h3 mb-1">HTTPS whitelist</h1>
			<p class="text-muted mb-0 small">
				When enabled, requests over HTTPS are allowed only from the listed IPs/CIDRs.
				This is enforced at the application layer.
			</p>
		</div>
		<div class="col-12 col-md-4">
			<div class="card border-0 bg-body-secondary h-100">
				<div class="card-body d-flex align-items-center justify-content-between">
					<span class="text-muted small">Entries</span>
					<span class="fs-4 fw-semibold"><?php echo count($cidrs); ?></span>
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
			<h2 class="h5 mb-3">Mode</h2>
			<form method="post" class="row g-2 align-items-end">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
				<input type="hidden" name="action" value="set_enabled" />
				<div class="col-12 col-sm-6">
					<label class="form-label small" for="enabled">Enable HTTPS whitelist</label>
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?php echo $enabled ? 'checked' : ''; ?> />
					</div>
					<div class="form-text">If enabled and entries are empty, all HTTPS requests will be blocked.</div>
				</div>
				<div class="col-12 col-sm-6 d-grid">
					<button type="submit" class="btn btn-primary btn-sm">Save</button>
				</div>
			</form>
		</div>
	</div>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-4">
			<h2 class="h5 mb-3">Allowed IPs / CIDRs</h2>

			<form method="post" class="row g-2 align-items-end mb-3">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
				<input type="hidden" name="action" value="add" />

				<div class="col-12">
					<label class="form-label small" for="cidrs_input">Add (one per line or comma-separated)</label>
					<textarea
						id="cidrs_input"
						name="cidrs_input"
						class="form-control form-control-sm"
						rows="3"
						placeholder="192.168.1.10&#10;192.168.1.0/24&#10;2001:db8::/32"
					></textarea>
				</div>

				<div class="col-12 col-sm-6 d-grid">
					<button type="submit" class="btn btn-primary btn-sm">Add entry</button>
				</div>
			</form>

			<div class="table-responsive">
				<table class="table table-hover table-striped align-middle small mb-0">
					<thead class="table-light">
						<tr>
							<th>Entry</th>
							<th style="width: 1%; white-space: nowrap;">Action</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($cidrs === []): ?>
							<tr>
								<td colspan="2" class="text-muted">No entries.</td>
							</tr>
						<?php endif; ?>
						<?php foreach ($cidrs as $c): ?>
							<tr>
								<td><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></td>
								<td class="text-end">
									<form method="post" onsubmit="return confirm('Remove this whitelist entry?');" class="m-0">
										<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
										<input type="hidden" name="action" value="remove" />
										<input type="hidden" name="cidr" value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" />
										<button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="text-muted small">
		Traffic IP detection uses <code>CF-Connecting-IP</code> when available (Cloudflare proxy) and falls back to <code>X-Forwarded-For</code>/<code>REMOTE_ADDR</code>.
	</div>
</div>
</body>
</html>

