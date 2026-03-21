<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_admin();

if (!isset($_SESSION['csrf_admin']) || !is_string($_SESSION['csrf_admin']) || strlen($_SESSION['csrf_admin']) < 32) {
	$_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_admin'];

/**
 * Escape a string for rsyslog ommysql double-quoted attributes.
 * Strips NUL/CR/LF defensively so a line cannot break out of the config line even if a caller forgets to sanitize.
 */
function admin_collector_escape_rsyslog_attr(string $s): string
{
	$s = str_replace(["\0", "\r", "\n"], '', $s);
	return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
}

/**
 * Sanitize collector target (hostname / IP). Rejects control chars and rsyslog metacharacters.
 */
function admin_collector_sanitize_target(string $raw): string
{
	$t = trim($raw);
	if ($t === '') {
		return 'logs.example.com';
	}
	if (preg_match('/[\s#;\'"\x00-\x1f]/', $t) === 1) {
		return 'logs.example.com';
	}
	return $t;
}

/**
 * Sanitize DB host for ommysql snippet: no whitespace, controls, quotes, backslash, or rsyslog line tokens.
 */
function admin_collector_sanitize_db_host(string $raw, string $default): string
{
	$s = trim($raw);
	if ($s === '' || mb_strlen($s) > 253) {
		return $default;
	}
	if (preg_match('/[\x00-\x1f\x7f\s;#"\']|\\\\/', $s) === 1) {
		return $default;
	}
	return $s;
}

/**
 * Sanitize MySQL database / user name for ommysql snippet (conservative unquoted-ident subset).
 */
function admin_collector_sanitize_db_ident(string $raw, string $default): string
{
	$s = trim($raw);
	if ($s === '' || mb_strlen($s) > 64) {
		return $default;
	}
	if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $s) !== 1) {
		return $default;
	}
	return $s;
}

/**
 * Sanitize password embedded in ommysql snippet: reject control characters (incl. newlines) that would inject rsyslog lines.
 * Control characters are checked on the original string before trim so values like "secret\n" cannot evade validation.
 */
function admin_collector_sanitize_db_password_snippet(string $raw, string $placeholder): string
{
	if ($raw === '') {
		return $placeholder;
	}
	if (preg_match('/[\x00-\x1f\x7f]/u', $raw) === 1) {
		return $placeholder;
	}
	$s = trim($raw);
	if ($s === '' || mb_strlen($s) > 512) {
		return $placeholder;
	}
	return $s;
}

$me = auth_current_user();
$displayName = is_array($me) ? (string) ($me['display_name'] ?? '') : '';
$navAdminActive = 'collector';
$siteTitle = htmlspecialchars($site_name ?? 'System Logs', ENT_QUOTES, 'UTF-8');

$error = '';
$warning = '';
$mode = 'rsyslog';
$transport = 'tcp';
$target = 'logs.example.com';
$port = 5514;
$dbHost = 'db';
$dbName = 'rsyslogdb';
$dbUser = 'rsyslog';
$dbPassForSnippet = 'CHANGE_ME';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedCsrf = $_POST['csrf_token'] ?? '';
	if (!is_string($postedCsrf) || !hash_equals($csrfToken, $postedCsrf)) {
		$error = 'Invalid session. Refresh and try again.';
	} else {
		$mode = isset($_POST['mode']) ? (string) $_POST['mode'] : 'rsyslog';
		if (!in_array($mode, ['rsyslog', 'sql'], true)) {
			$mode = 'rsyslog';
		}
		$transport = isset($_POST['transport']) ? (string) $_POST['transport'] : 'tcp';
		if (!in_array($transport, ['tcp', 'udp'], true)) {
			$transport = 'tcp';
		}
		$target = admin_collector_sanitize_target((string) ($_POST['target'] ?? 'logs.example.com'));
		$port = (int) ($_POST['port'] ?? 5514);
		if ($port < 1 || $port > 65535) {
			$port = 5514;
		}
		$dbHostRaw = trim((string) ($_POST['db_host'] ?? ''));
		$dbNameRaw = trim((string) ($_POST['db_name'] ?? ''));
		$dbUserRaw = trim((string) ($_POST['db_user'] ?? ''));
		$dbHost = admin_collector_sanitize_db_host((string) ($_POST['db_host'] ?? ''), 'db');
		$dbName = admin_collector_sanitize_db_ident((string) ($_POST['db_name'] ?? ''), 'rsyslogdb');
		$dbUser = admin_collector_sanitize_db_ident((string) ($_POST['db_user'] ?? ''), 'rsyslog');
		$dbPassRaw = (string) ($_POST['db_pass'] ?? '');
		$dbPassForSnippet = admin_collector_sanitize_db_password_snippet($dbPassRaw, 'CHANGE_ME');

		$sanitizedAway = [];
		if ($dbHostRaw !== '' && $dbHost === 'db' && $dbHostRaw !== 'db') {
			$sanitizedAway[] = 'DB host';
		}
		if ($dbNameRaw !== '' && $dbName === 'rsyslogdb' && $dbNameRaw !== 'rsyslogdb') {
			$sanitizedAway[] = 'DB name';
		}
		if ($dbUserRaw !== '' && $dbUser === 'rsyslog' && $dbUserRaw !== 'rsyslog') {
			$sanitizedAway[] = 'DB user';
		}
		// Keep in sync with admin_collector_sanitize_db_password_snippet(): control check on raw, length/empty on trimmed.
		$dbPassTrimmed = trim($dbPassRaw);
		$passRejected = $dbPassRaw !== '' && (
			preg_match('/[\x00-\x1f\x7f]/u', $dbPassRaw) === 1
			|| mb_strlen($dbPassTrimmed) > 512
			|| $dbPassTrimmed === ''
		);
		if ($passRejected) {
			$sanitizedAway[] = 'DB password';
		}
		if ($sanitizedAway !== []) {
			$warning = 'Some direct-SQL fields contained invalid characters (e.g. newlines, spaces, or symbols not allowed in the snippet). Reset to safe defaults: ' . implode(', ', $sanitizedAway) . '.';
		}
	}
}

$snippet = '';
if ($mode === 'rsyslog') {
	$prefix = $transport === 'udp' ? '@' : '@@';
	$snippet = "# /etc/rsyslog.d/60-rsyslog-webui.conf\n"
		. '*.* ' . $prefix . $target . ':' . $port . "\n\n"
		. "# restart service\n"
		. "sudo systemctl restart rsyslog\n";
} else {
	$eh = admin_collector_escape_rsyslog_attr($dbHost);
	$en = admin_collector_escape_rsyslog_attr($dbName);
	$eu = admin_collector_escape_rsyslog_attr($dbUser);
	$ep = admin_collector_escape_rsyslog_attr($dbPassForSnippet);
	$snippet = "# /etc/rsyslog.d/60-rsyslog-webui-sql.conf\n"
		. "module(load=\"ommysql\")\n"
		. '*.* action(type="ommysql" server="' . $eh . '" db="' . $en . '" uid="' . $eu . '" pwd="' . $ep . '")' . "\n\n"
		. "sudo systemctl restart rsyslog\n";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Collector config - <?php echo $siteTitle; ?></title>
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
			<h1 class="h3 mb-1">Collector configuration</h1>
			<p class="text-muted mb-0 small">Generate a copy/paste rsyslog snippet based on your destination pattern.</p>
		</div>
	</div>

	<?php if ($error !== ''): ?>
		<div class="alert alert-warning"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>
	<?php if ($warning !== ''): ?>
		<div class="alert alert-info"><?php echo htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-4">
			<form method="post" class="row g-3">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
				<div class="col-12 col-md-4">
					<label class="form-label small" for="mode">Destination mode</label>
					<select class="form-select" id="mode" name="mode">
						<option value="rsyslog" <?php echo $mode === 'rsyslog' ? 'selected' : ''; ?>>Forward to rsyslog collector</option>
						<option value="sql" <?php echo $mode === 'sql' ? 'selected' : ''; ?>>Write direct to SQL</option>
					</select>
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label small" for="transport">Transport</label>
					<select class="form-select" id="transport" name="transport">
						<option value="tcp" <?php echo $transport === 'tcp' ? 'selected' : ''; ?>>TCP</option>
						<option value="udp" <?php echo $transport === 'udp' ? 'selected' : ''; ?>>UDP</option>
					</select>
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label small" for="port">Port</label>
					<input class="form-control" type="number" id="port" name="port" min="1" max="65535" value="<?php echo (int) $port; ?>">
				</div>

				<div class="col-12 col-md-6">
					<label class="form-label small" for="target">Collector target (IP/domain)</label>
					<input class="form-control" id="target" name="target" value="<?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
				</div>

				<div class="col-12"><hr></div>
				<div class="col-12">
					<h2 class="h6">Direct SQL options</h2>
					<p class="text-muted small mb-0">Used only for the ommysql snippet. Host: letters, digits, dots, colons, hyphens, underscores (no spaces or quotes). DB name and user: <code class="small">[a-zA-Z0-9][a-zA-Z0-9_-]*</code> (max 64). Password: no control characters or newlines (max 512).</p>
				</div>
				<div class="col-12 col-md-3">
					<label class="form-label small" for="db_host">DB host</label>
					<input class="form-control" id="db_host" name="db_host" value="<?php echo htmlspecialchars($dbHost, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
				</div>
				<div class="col-12 col-md-3">
					<label class="form-label small" for="db_name">DB name</label>
					<input class="form-control" id="db_name" name="db_name" value="<?php echo htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
				</div>
				<div class="col-12 col-md-3">
					<label class="form-label small" for="db_user">DB user</label>
					<input class="form-control" id="db_user" name="db_user" value="<?php echo htmlspecialchars($dbUser, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
				</div>
				<div class="col-12 col-md-3">
					<label class="form-label small" for="db_pass">DB password</label>
					<input class="form-control" type="password" id="db_pass" name="db_pass" value="" placeholder="Leave empty to use CHANGE_ME in snippet" autocomplete="new-password">
				</div>
				<div class="col-12">
					<button type="submit" class="btn btn-primary">Generate snippet</button>
				</div>
			</form>
		</div>
	</div>

	<div class="card border-0 shadow-sm">
		<div class="card-body p-4">
			<div class="d-flex align-items-center justify-content-between mb-2">
				<h2 class="h5 mb-0">Generated config</h2>
				<button type="button" class="btn btn-sm btn-outline-secondary" id="copyBtn"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copy</button>
			</div>
			<pre class="mb-0"><code id="configSnippet"><?php echo htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8'); ?></code></pre>
		</div>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
<script>
(function () {
	var btn = document.getElementById('copyBtn');
	var snippet = document.getElementById('configSnippet');
	if (!btn || !snippet) return;
	var defaultHtml = btn.innerHTML;
	btn.addEventListener('click', async function () {
		try {
			await navigator.clipboard.writeText(snippet.textContent || '');
			btn.textContent = 'Copied';
			setTimeout(function () { btn.innerHTML = defaultHtml; }, 1500);
		} catch (e) {}
	});
})();
</script>
</body>
</html>
