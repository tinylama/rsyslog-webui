<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/azure-oidc.php';
require_admin();

$me = auth_current_user();
$displayName = is_array($me) ? (string) ($me['display_name'] ?? '') : '';
$navAdminActive = 'entra';

if (!isset($_SESSION['csrf_admin']) || !is_string($_SESSION['csrf_admin']) || strlen($_SESSION['csrf_admin']) < 32) {
	$_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_admin'];

$pdo = auth_db();
auth_ensure_app_settings_schema($pdo);

$error = '';
$success = '';

/**
 * Validate Azure object IDs in canonical GUID form.
 */
function entra_is_guid(string $v): bool
{
	return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $v) === 1;
}

function entra_is_absolute_url(string $v): bool
{
	$parsed = parse_url($v);
	if (!is_array($parsed)) {
		return false;
	}
	$scheme = strtolower((string) ($parsed['scheme'] ?? ''));
	$host = (string) ($parsed['host'] ?? '');
	return ($scheme === 'http' || $scheme === 'https') && $host !== '';
}

function entra_is_emailish(string $v): bool
{
	// UPN is email-shaped; we validate with email rules to keep the UX simple.
	return filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedCsrf = $_POST['csrf_token'] ?? '';
	if (!is_string($postedCsrf) || !hash_equals($_SESSION['csrf_admin'], $postedCsrf)) {
		$error = 'Invalid session. Refresh and try again.';
	} else {
		$action = (string) ($_POST['action'] ?? 'save');

		if ($action === 'enable_graph_mail') {
			$detail = null;
			$ok = false;
			try {
				require_once __DIR__ . '/../includes/graph-mail.php';
				app_settings_refresh_from_db();
				$token = graph_mail_access_token();
				if ($token !== null) {
					$ok = graph_mail_send_test();
				}
				$detail = function_exists('graph_mail_last_error') ? graph_mail_last_error() : null;
			} catch (Throwable $e) {
				$detail = $e->getMessage();
			}

			if ($ok) {
				app_settings_save($pdo, 'graph_mail_enabled', '1', false);
				app_settings_refresh_from_db();
				$success = 'Graph mail enabled (email verification codes will send).';
				auth_log_email_event('graph_mail_test', (string) ($graph_mail_sender ?? ''), 'sent', 'enable_graph_mail');
			} else {
				$detail = is_string($detail) && $detail !== '' ? $detail : 'token verification failed';
				$error = 'Could not enable Graph mail. ' . $detail;
				auth_log_email_event('graph_mail_test', (string) ($graph_mail_sender ?? ''), 'failed', $detail);
			}
		} elseif ($action === 'disable_graph_mail') {
			app_settings_save($pdo, 'graph_mail_enabled', '0', false);
			app_settings_refresh_from_db();
			$success = 'Graph mail disabled (email verification codes will no longer send).';
		} elseif ($action === 'clear_graph_entra_details') {
			app_settings_save($pdo, 'azure_ad_enabled', '0', false);
			app_settings_save($pdo, 'graph_mail_enabled', '0', false);
			app_settings_save($pdo, 'azure_ad_tenant_id', '', false);
			app_settings_save($pdo, 'azure_ad_client_id', '', false);
			app_settings_save($pdo, 'azure_ad_client_secret', '', true);
			app_settings_save($pdo, 'azure_ad_redirect_uri', '', false);
			app_settings_save($pdo, 'azure_ad_admin_group_id', '', false);
			app_settings_save($pdo, 'graph_mail_sender', '', false);
			app_settings_refresh_from_db();
			$success = 'Entra + Graph mail details cleared.';
		} else {
			$en = isset($_POST['azure_ad_enabled']) && $_POST['azure_ad_enabled'] === '1';

			$tenantId = trim((string) ($_POST['azure_ad_tenant_id'] ?? ''));
			$clientId = trim((string) ($_POST['azure_ad_client_id'] ?? ''));
			$sec = trim((string) ($_POST['azure_ad_client_secret'] ?? ''));
			// Redirect URI override removed from UI.
			// Always use the default derived callback URL from host + HTTPS scheme.
			$redirectUri = '';
			$adminGroupId = trim((string) ($_POST['azure_ad_admin_group_id'] ?? ''));
			$graphMailSender = trim((string) ($_POST['graph_mail_sender'] ?? ''));

			$storedSecretPresent = trim((string) app_settings_get($pdo, 'azure_ad_client_secret', true)) !== '';

			// Validate formats for any fields user provides.
			if ($tenantId !== '' && !entra_is_guid($tenantId)) {
				$error = 'Directory (tenant) ID must be a GUID.';
			} elseif ($clientId !== '' && !entra_is_guid($clientId)) {
				$error = 'Application (client) ID must be a GUID.';
			} elseif ($adminGroupId !== '' && !entra_is_guid($adminGroupId)) {
				$error = 'Admin group object ID must be a GUID.';
			} elseif ($graphMailSender !== '' && !entra_is_emailish($graphMailSender)) {
				$error = 'Graph mail sender must look like an email/UPN (e.g. mailbox@yourtenant.onmicrosoft.com).';
			} elseif ($en) {
				// Enabling Microsoft sign-in requires the basics.
				if ($tenantId === '' || !entra_is_guid($tenantId)) {
					$error = 'Directory (tenant) ID is required to enable Microsoft sign-in.';
				} elseif ($clientId === '' || !entra_is_guid($clientId)) {
					$error = 'Application (client) ID is required to enable Microsoft sign-in.';
				} elseif ($sec === '' && !$storedSecretPresent) {
					$error = 'Client secret is required to enable Microsoft sign-in (paste one, or keep the stored secret).';
				}
			}

			if ($error === '') {
				app_settings_save($pdo, 'azure_ad_enabled', $en ? '1' : '0', false);
				app_settings_save($pdo, 'azure_ad_tenant_id', $tenantId, false);
				app_settings_save($pdo, 'azure_ad_client_id', $clientId, false);
				if ($sec !== '') {
					app_settings_save($pdo, 'azure_ad_client_secret', $sec, true);
				}
					// Clear any previously stored override to fully remove the feature.
					app_settings_save($pdo, 'azure_ad_redirect_uri', '', false);
				app_settings_save($pdo, 'azure_ad_admin_group_id', $adminGroupId, false);
				app_settings_save($pdo, 'graph_mail_sender', $graphMailSender, false);

				// Best-effort verification:
				// - If the client secret is valid, Graph token acquisition should succeed (catches invalid_client).
				// - Graph sendMail may still be forbidden (403) due to Exchange/Graph mail permissions.
				$hasSecret = $sec !== '' || $storedSecretPresent;
				$sendOk = false;
				$tokenOk = false;
				if ($tenantId !== '' && $clientId !== '' && $hasSecret) {
					require_once __DIR__ . '/../includes/graph-mail.php';
					app_settings_refresh_from_db();
					$token = graph_mail_access_token();
					if ($token !== null) {
						$tokenOk = true;
						$sendOk = graph_mail_send_test();
					}
				}

				if ($sendOk) {
					app_settings_save($pdo, 'graph_mail_enabled', '1', false);
					app_settings_save($pdo, 'azure_ad_enabled', $en ? '1' : '0', false);
					$success = 'Settings saved and verified. Sign-in + email verification codes activated.';
					auth_log_email_event('graph_mail_test', $graphMailSender, 'sent', 'settings_verify');
				} else {
					app_settings_save($pdo, 'azure_ad_enabled', ($en && $tokenOk) ? '1' : '0', false);
					$detail = function_exists('graph_mail_last_error') ? graph_mail_last_error() : null;
					$detail = is_string($detail) && $detail !== '' ? $detail : 'token verification failed';
					$error = ($en && !$tokenOk)
						? 'Settings saved, but verification failed (Microsoft sign-in could not be validated). ' . $detail
						: 'Settings saved, but email verification codes could not be enabled. ' . $detail;

					$detailLower = strtolower($detail);
					if (str_contains($detailLower, 'erroraccessdenied') || str_contains($detailLower, 'access is denied')) {
						$error .= ' Tip: Graph token was acquired, but sending was denied. Ensure your Entra app has the `Mail.Send` application permission with admin consent, and that Exchange app-only access is granted for the configured sender mailbox UPN.';
					}
					auth_log_email_event('graph_mail_test', $graphMailSender, 'failed', $detail);
				}
			}
		}
	}
}

app_settings_refresh_from_db();

global $azure_ad_enabled, $azure_ad_tenant_id, $azure_ad_client_id, $azure_ad_client_secret, $azure_ad_redirect_uri, $azure_ad_admin_group_id;
global $graph_mail_sender, $graph_mail_enabled;

$graphMailEnabled = false;
if (isset($graph_mail_enabled)) {
	$graphMailEnabled = ($graph_mail_enabled === '1') || (strtolower((string) $graph_mail_enabled) === 'true');
}

$settings = auth_azure_settings();
$callbackUrl = auth_azure_effective_redirect_uri($settings);
$siteTitle = htmlspecialchars($site_name ?? 'System Logs', ENT_QUOTES, 'UTF-8');
$hasStoredSecret = trim((string) ($azure_ad_client_secret ?? '')) !== '' || app_settings_get($pdo, 'azure_ad_client_secret', true) !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Entra settings — <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="../css/custom.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
</head>
<body>
<div class="app-shell py-3 py-md-4">
	<?php include __DIR__ . '/../includes/partials/navbar-admin.php'; ?>

	<h1 class="h3 mb-1"><i class="bi bi-microsoft me-2" aria-hidden="true"></i>Microsoft Entra ID</h1>
	<p class="text-muted mb-4">Database values override environment variables. Client secrets are encrypted at rest using <code>RSYSLOG_APP_SECRET</code> (or <code>session_secret</code>).</p>

	<?php if ($error !== ''): ?>
		<div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>
	<?php if ($success !== ''): ?>
		<div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-4">
			<h2 class="h5">Guided setup</h2>
			<p class="small mb-2">Creating the Entra app registration requires appropriate directory roles. This UI does not call high-privilege Microsoft Graph to provision apps automatically.</p>
			<ul class="small mb-3">
				<li>Register an app in Entra, add a web redirect URI matching the callback below, enable ID tokens, create a client secret.</li>
				<li>For SSO: delegated permissions <code>openid</code>, <code>profile</code>, <code>email</code>, <code>offline_access</code> (and optional <code>GroupMember.Read.All</code> if you emit <code>groups</code> for admin mapping).</li>
				<li>For email verification codes (password reset + email change): application permission <code>Mail.Send</code> with admin consent; set a sender mailbox UPN below.</li>
			</ul>
			<div class="d-flex flex-wrap gap-2">
				<a class="btn btn-sm btn-outline-primary" href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">App registrations <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
				<a class="btn btn-sm btn-outline-primary" href="https://entra.microsoft.com/#view/Microsoft_AAD_IAM/ManagedAppsMenuBlade/~/Overview" target="_blank" rel="noopener">Enterprise applications <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
			</div>
			<hr class="my-4">
			<label class="form-label small text-muted">OAuth redirect / callback URL (add this in Entra)</label>
			<div class="input-group input-group-sm mb-2">
				<input type="text" class="form-control font-monospace small" id="copyCallback" readonly value="<?php echo htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8'); ?>" />
				<button type="button" class="btn btn-outline-secondary" id="btnCopyCb" title="Copy"><i class="bi bi-clipboard" aria-hidden="true"></i></button>
			</div>
		</div>
	</div>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-4">
			<h2 class="h5 mb-3">Configuration</h2>
			<form method="post" class="row g-3">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
				<div class="col-12">
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" name="azure_ad_enabled" value="1" id="azEn" <?php echo !empty($azure_ad_enabled) ? ' checked' : ''; ?> />
						<label class="form-check-label" for="azEn">Enable Microsoft sign-in</label>
					</div>
				</div>
				<div class="col-md-6">
					<label class="form-label" for="tenant">Directory (tenant) ID</label>
					<input class="form-control font-monospace" id="tenant" name="azure_ad_tenant_id" value="<?php echo htmlspecialchars((string) $azure_ad_tenant_id, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" />
				</div>
				<div class="col-md-6">
					<label class="form-label" for="client">Application (client) ID</label>
					<input class="form-control font-monospace" id="client" name="azure_ad_client_id" value="<?php echo htmlspecialchars((string) $azure_ad_client_id, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" />
				</div>
				<div class="col-12">
					<label class="form-label" for="secret">Client secret</label>
					<input class="form-control font-monospace" type="password" id="secret" name="azure_ad_client_secret" value="" autocomplete="new-password" placeholder="<?php echo $hasStoredSecret ? 'Leave blank to keep current secret' : 'Paste new secret'; ?>" />
				</div>
				<!-- Redirect URI override removed (always uses derived callback). -->
				<div class="col-12">
					<label class="form-label" for="adming">Admin group object ID</label>
					<input class="form-control font-monospace small" id="adming" name="azure_ad_admin_group_id" value="<?php echo htmlspecialchars((string) $azure_ad_admin_group_id, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" />
				</div>
				<div class="col-12">
					<label class="form-label" for="gms">Graph mail sender (UPN)</label>
					<input class="form-control" id="gms" name="graph_mail_sender" value="<?php echo htmlspecialchars((string) $graph_mail_sender, ENT_QUOTES, 'UTF-8'); ?>" placeholder="mailbox@yourtenant.onmicrosoft.com" autocomplete="off" />
					<div class="form-text">Used with application <code>Mail.Send</code> to send email verification codes.</div>
				</div>
				<div class="col-12">
					<button type="submit" class="btn btn-primary">Save settings</button>
				</div>
				<div class="col-12 d-flex flex-wrap gap-2">
					<?php if ($graphMailEnabled): ?>
						<button type="submit" class="btn btn-outline-secondary" name="action" value="disable_graph_mail">
							Disable email verification codes
						</button>
					<?php else: ?>
						<button type="submit" class="btn btn-outline-success" name="action" value="enable_graph_mail">
							Enable email verification codes
						</button>
					<?php endif; ?>
					<button type="submit" class="btn btn-outline-danger" name="action" value="clear_graph_entra_details" onclick="return confirm('Clear stored Entra + Graph settings? This will disable Microsoft sign-in and email verification codes.');">
						Delete Entra + Graph details
					</button>
				</div>
			</form>
		</div>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
<script>
document.getElementById('btnCopyCb')?.addEventListener('click', function () {
	var el = document.getElementById('copyCallback');
	if (!el) return;
	el.select();
	document.execCommand('copy');
});
</script>
</body>
</html>
