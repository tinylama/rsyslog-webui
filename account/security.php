<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/graph-mail.php';

require_login();
auth_bootstrap_once();

$me = auth_current_user();
if ($me === null) {
	header('Location: /login.php', true, 302);
	exit;
}
$uid = (int) $me['id'];

if (!isset($_SESSION['csrf_account']) || !is_string($_SESSION['csrf_account']) || strlen($_SESSION['csrf_account']) < 32) {
	$_SESSION['csrf_account'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_account'];

if (!isset($_SESSION['csrf_webauthn']) || !is_string($_SESSION['csrf_webauthn']) || strlen($_SESSION['csrf_webauthn']) < 32) {
	$_SESSION['csrf_webauthn'] = bin2hex(random_bytes(32));
}

$pdo = auth_db();
$error = '';
$success = '';
$securityBaseline = auth_security_baseline_status();
$securityBaselineSafe = (bool) ($securityBaseline['safe'] ?? false);
$securityBaselineDetail = (string) ($securityBaseline['detail'] ?? '');
$graphMailConfigured = graph_mail_is_configured();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$t = $_POST['csrf_token'] ?? '';
	if (!is_string($t) || !hash_equals($csrf, $t)) {
		$error = 'Invalid session. Refresh and try again.';
	} else {
		$action = (string) ($_POST['action'] ?? '');
			if ($action === 'update_email') {
			$em = trim((string) ($_POST['email'] ?? ''));
			if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
				$error = 'Invalid email address.';
			} else {
					if ($em === '') {
						$error = 'Email address cannot be empty.';
					} elseif (!$graphMailConfigured) {
						// Allow setting the email even when Graph mail isn't configured yet.
						// Password reset / email-change verification will require it later.
						$pdo->prepare('UPDATE Users SET Email = :e WHERE ID = :id')->execute([
							':e' => $em,
							':id' => $uid,
						]);
						unset($_SESSION['email_change_pending_new_email'], $_SESSION['email_change_pending_sent_at']);
						$success = 'Email updated. Password reset and email change verification require Graph mail configuration.';
					} else {
						$_SESSION['email_change_pending_new_email'] = $em;
						$_SESSION['email_change_pending_sent_at'] = time();
						if (!auth_email_code_challenge_start($pdo, $uid, $em)) {
						$detail = graph_mail_last_error();
						$error = $detail !== null && $detail !== ''
							? ('Could not send verification code. ' . $detail)
							: 'Could not send verification code. Check Graph mail configuration.';
						} else {
							$success = 'Verification code sent. Enter it to confirm the email change.';
						}
					}
			}
			} elseif ($action === 'email_change_confirm') {
				$pendingNew = isset($_SESSION['email_change_pending_new_email']) && is_string($_SESSION['email_change_pending_new_email'])
					? trim($_SESSION['email_change_pending_new_email']) : '';
				$codeRaw = trim((string) ($_POST['email_code'] ?? ''));
				$code = preg_replace('/\D+/', '', $codeRaw) ?? '';

				if ($pendingNew === '' || !filter_var($pendingNew, FILTER_VALIDATE_EMAIL)) {
					$error = 'Start email verification first.';
				} elseif (!preg_match('/^\d{6}$/', $code)) {
					$error = 'Enter the 6-digit verification code.';
				} else {
					if (!auth_email_code_challenge_verify($pdo, $uid, $code)) {
						$error = 'Invalid or expired verification code.';
					} else {
						$pdo->prepare('UPDATE Users SET Email = :e WHERE ID = :id')->execute([
							':e' => $pendingNew,
							':id' => $uid,
						]);
						unset($_SESSION['email_change_pending_new_email'], $_SESSION['email_change_pending_sent_at']);
						$success = 'Email updated.';
					}
				}
		} elseif ($action === 'totp_start') {
			if (!$securityBaselineSafe) {
				$error = 'Security configuration is not safe to enroll an authenticator app. Update placeholder/default secrets first.';
			}
			if ($error === '') {
			auth_load_otp_composer();
			if (!class_exists('OTPHP\\TOTP')) {
				$error = 'TOTP library is not available on this server (OTPhp missing).';
			} else {
				$totp = \OTPHP\TOTP::generate();
				$sec = $totp->getSecret();
				$_SESSION['totp_enroll_secret'] = $sec;
				$success = 'Scan the QR code, then enter a code to confirm.';
			}
			}
		} elseif ($action === 'totp_confirm') {
			if (!$securityBaselineSafe) {
				$error = 'Security configuration is not safe to confirm authenticator enrollment. Update placeholder/default secrets first.';
			}
			if ($error === '') {
			$sec = isset($_SESSION['totp_enroll_secret']) && is_string($_SESSION['totp_enroll_secret'])
				? $_SESSION['totp_enroll_secret'] : '';
			$codeRaw = trim((string) ($_POST['totp_code'] ?? ''));
			// Allow user paste with spaces or other separators.
			$code = preg_replace('/\D+/', '', $codeRaw) ?? '';
			if ($sec === '') {
				$error = 'Start enrollment first.';
			} elseif (!preg_match('/^\d{6}$/', $code)) {
				$error = 'Enter the 6-digit authenticator code.';
			} else {
				auth_load_otp_composer();
				if (!class_exists('OTPHP\\TOTP')) {
					$error = 'TOTP library is not available on this server (OTPhp missing).';
				} else {
					$t = \OTPHP\TOTP::createFromSecret($sec);
					// TOTP codes can be entered shortly after the 30s step flips.
					// Use a small leeway window (seconds) for user drift.
					$leeway = min(10, $t->getPeriod() - 1);
					if (!$t->verify($code, null, $leeway)) {
						$error = 'Invalid code.';
					} else {
						$enc = auth_totp_store_encrypt($sec);
						$pdo->prepare('UPDATE Users SET MfaTotpSecret = :s WHERE ID = :id')->execute([
							':s' => $enc,
							':id' => $uid,
						]);
						unset($_SESSION['totp_enroll_secret']);
						$success = 'Authenticator app linked.';
					}
				}
			}
			}
		} elseif ($action === 'totp_disable') {
			$pdo->prepare('UPDATE Users SET MfaTotpSecret = NULL WHERE ID = :id')->execute([':id' => $uid]);
			unset($_SESSION['totp_enroll_secret']);
			$success = 'Authenticator app removed.';
		} elseif ($action === 'delete_passkey') {
			$cid = (int) ($_POST['cred_id'] ?? 0);
			if ($cid <= 0) {
				$error = 'Invalid passkey id.';
			} else {
				$webauthnFile = __DIR__ . '/../includes/webauthn-rsyslog.php';
				if (!is_file($webauthnFile)) {
					$error = 'Passkey support is not available on this server.';
				} else {
					require_once $webauthnFile;
					if (!function_exists('auth_webauthn_delete_credential')) {
						$error = 'Passkey support is not available on this server.';
					} elseif (auth_webauthn_delete_credential($pdo, $uid, $cid)) {
						$success = 'Passkey removed.';
					} else {
						$error = 'Could not remove passkey.';
					}
				}
			}
		} elseif ($action === 'set_session_timeout') {
			// value is seconds (0 = no timeout)
			$raw = (string) ($_POST['timeout_seconds'] ?? '');
			if (!preg_match('/^\d+$/', $raw)) {
				$error = 'Invalid session timeout.';
			} else {
				$sec = (int) $raw;
				if (!in_array($sec, [0, 900, 1800, 3600], true)) {
					$error = 'Invalid session timeout.';
				} else {
					$pdo->prepare('UPDATE Users SET SessionTimeoutSeconds = :s WHERE ID = :id')->execute([
						':s' => $sec,
						':id' => $uid,
					]);
					$_SESSION['session_timeout_seconds'] = $sec;
					$_SESSION['last_activity'] = time();
					$success = $sec === 0 ? 'Session timeout disabled.' : 'Session timeout updated.';
				}
			}
		}
	}
}

$row = auth_user_row_by_id($pdo, $uid);
$email = $row && isset($row['Email']) ? (string) $row['Email'] : '';
$emailMfaWasEnabled = $row && !empty($row['MfaEmailEnabled']);
if ($emailMfaWasEnabled) {
	// Email MFA was removed in favor of TOTP + passkeys.
	// Clear the flag so users don't think email codes are used at sign-in.
	$pdo->prepare('UPDATE Users SET MfaEmailEnabled = 0 WHERE ID = :id')->execute([':id' => $uid]);
}
$hasTotp = $row && isset($row['MfaTotpSecret']) && is_string($row['MfaTotpSecret']) && trim($row['MfaTotpSecret']) !== '';
$totpPending = isset($_SESSION['totp_enroll_secret']) && is_string($_SESSION['totp_enroll_secret']) && $_SESSION['totp_enroll_secret'] !== '';
$emailChangePendingNew = isset($_SESSION['email_change_pending_new_email']) && is_string($_SESSION['email_change_pending_new_email'])
	? trim($_SESSION['email_change_pending_new_email']) : '';
$emailChangePending = $emailChangePendingNew !== '' && !empty($_SESSION['email_change_pending_sent_at']);

$stmtC = $pdo->prepare('SELECT ID, FriendlyName, CreatedAt FROM UserWebAuthnCredentials WHERE UserID = :u ORDER BY CreatedAt DESC');
$stmtC->execute([':u' => $uid]);
$creds = $stmtC->fetchAll(PDO::FETCH_ASSOC);

$stmtU = $pdo->prepare('SELECT Username FROM Users WHERE ID = :id LIMIT 1');
$stmtU->execute([':id' => $uid]);
$username = (string) $stmtU->fetchColumn();

$sessionTimeoutSeconds = 1800;
try {
	$sStmt = $pdo->prepare('SELECT SessionTimeoutSeconds FROM Users WHERE ID = :id LIMIT 1');
	$sStmt->execute([':id' => $uid]);
	$v = $sStmt->fetchColumn();
	$sessionTimeoutSeconds = is_numeric($v) ? (int) $v : 1800;
	if ($sessionTimeoutSeconds < 0) $sessionTimeoutSeconds = 1800;
} catch (Throwable $e) {}

$issuer = $site_name ?? 'App';
$qrUri = '';
if ($totpPending) {
	auth_load_otp_composer();
	if (class_exists('OTPHP\\TOTP')) {
		$t = \OTPHP\TOTP::createFromSecret($_SESSION['totp_enroll_secret']);
		$t->setLabel($username);
		$t->setIssuer($issuer);
		$qrUri = $t->getQrCodeUri(
			'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=[DATA]',
			'[DATA]'
		);
	}
}

$siteTitle = htmlspecialchars($site_name ?? 'System Logs', ENT_QUOTES, 'UTF-8');
$csrfEnc = htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Account security — <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
	<script>
	(function(){ try { if (localStorage.getItem('darkSwitch') === 'dark') document.documentElement.setAttribute('data-bs-theme','dark'); } catch(e){} })();
	</script>
</head>
<body class="bg-body-secondary">
<div class="app-shell py-3 py-md-4">
	<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-3 app-navbar">
		<div class="container-fluid px-0 px-sm-2">
			<a class="navbar-brand" href="../"><?php echo $siteTitle; ?></a>
			<div class="ms-auto d-flex align-items-center gap-2">
				<div class="form-check form-switch m-0">
					<input class="form-check-input" type="checkbox" id="darkSwitch" aria-label="Dark mode">
					<label class="form-check-label small" for="darkSwitch">Dark</label>
				</div>
				<div class="dropdown">
					<a class="dropdown-toggle d-flex align-items-center gap-2 text-decoration-none" href="#" id="accountSecurityUserMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
						<i class="bi bi-person-circle fs-5" aria-hidden="true"></i>
						<span class="text-truncate" style="max-width: 10rem">
							<?php echo htmlspecialchars(($username !== '' ? $username : 'Account'), ENT_QUOTES, 'UTF-8'); ?>
						</span>
					</a>
					<ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="accountSecurityUserMenu">
						<li>
							<a class="dropdown-item" href="../logout.php">
								<i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out
							</a>
						</li>
					</ul>
				</div>
			</div>
		</div>
	</nav>

	<h1 class="h3 mb-4">Account security</h1>

	<?php if ($error !== ''): ?>
		<div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>
	<?php if ($success !== ''): ?>
		<div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>
	<?php if (!$securityBaselineSafe && $securityBaselineDetail !== ''): ?>
		<div class="alert alert-warning mb-3">
			<?php echo htmlspecialchars($securityBaselineDetail, ENT_QUOTES, 'UTF-8'); ?>
		</div>
	<?php endif; ?>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-4">
			<h2 class="h5" id="email-section">Email</h2>
			<p class="text-muted small">
				Used for password reset and for verifying any email address changes.
			</p>

			<?php if ($emailChangePending): ?>
				<div class="alert alert-info small">
					Verification code sent to <strong><?php echo htmlspecialchars($emailChangePendingNew, ENT_QUOTES, 'UTF-8'); ?></strong>.
				</div>

				<form method="post" class="row g-2 align-items-end">
					<input type="hidden" name="csrf_token" value="<?php echo $csrfEnc; ?>" />
					<input type="hidden" name="action" value="email_change_confirm" />
					<div class="col-md-8">
						<label class="form-label small" for="emailCode">6-digit verification code</label>
						<input class="form-control" id="emailCode" name="email_code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required />
					</div>
					<div class="col-auto">
						<button type="submit" class="btn btn-success">Confirm email</button>
					</div>
				</form>

				<?php if ($graphMailConfigured): ?>
					<form method="post" class="mt-3 d-flex gap-2 align-items-center">
						<input type="hidden" name="csrf_token" value="<?php echo $csrfEnc; ?>" />
						<input type="hidden" name="action" value="update_email" />
						<input type="hidden" name="email" value="<?php echo htmlspecialchars($emailChangePendingNew, ENT_QUOTES, 'UTF-8'); ?>" />
						<button type="submit" class="btn btn-outline-secondary btn-sm">Resend code</button>
					</form>
				<?php endif; ?>
			<?php else: ?>
				<form method="post" class="row g-2 align-items-end">
					<input type="hidden" name="csrf_token" value="<?php echo $csrfEnc; ?>" />
					<input type="hidden" name="action" value="update_email" />
					<div class="col-md-8">
						<label class="form-label" for="email">Address</label>
						<input class="form-control" id="email" name="email" type="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="email" required />
					</div>
					<div class="col-auto">
						<button type="submit" class="btn btn-primary"><?php echo $graphMailConfigured ? 'Send verification code' : 'Save email'; ?></button>
					</div>
				</form>

				<p class="small text-muted mb-0 mt-2">
					<?php if ($graphMailConfigured): ?>
						If you ever forget your password, use this email from the <a href="../password-reset.php">Forgot password</a> flow.
					<?php else: ?>
						Email saved now. Password reset and email-change verification will require Graph mail configuration (Admin → Entra settings).
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-4">
			<h2 class="h5" id="totp-section">Authenticator app (TOTP)</h2>
			<?php if ($hasTotp): ?>
				<p class="small text-success mb-2">An authenticator is enrolled.</p>
				<form method="post" onsubmit="return confirm('Remove TOTP from your account?');">
					<input type="hidden" name="csrf_token" value="<?php echo $csrfEnc; ?>" />
					<input type="hidden" name="action" value="totp_disable" />
					<button type="submit" class="btn btn-outline-danger btn-sm">Remove TOTP</button>
				</form>
			<?php else: ?>
				<?php if (!$totpPending): ?>
					<form method="post">
						<input type="hidden" name="csrf_token" value="<?php echo $csrfEnc; ?>" />
						<input type="hidden" name="action" value="totp_start" />
						<button type="submit" class="btn btn-primary" <?php echo $securityBaselineSafe ? '' : 'disabled'; ?>>Start enrollment</button>
					</form>
				<?php else: ?>
					<p class="small">Scan with your authenticator app:</p>
					<img src="<?php echo htmlspecialchars($qrUri, ENT_QUOTES, 'UTF-8'); ?>" width="200" height="200" alt="TOTP QR code" class="border rounded mb-3" />
					<form method="post" class="row g-2">
						<input type="hidden" name="csrf_token" value="<?php echo $csrfEnc; ?>" />
						<input type="hidden" name="action" value="totp_confirm" />
						<div class="col-auto">
							<input class="form-control" name="totp_code" inputmode="numeric" autocomplete="one-time-code" placeholder="6-digit code" required />
						</div>
						<div class="col-auto">
							<button type="submit" class="btn btn-success" <?php echo $securityBaselineSafe ? '' : 'disabled'; ?>>Confirm</button>
						</div>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-4">
			<h2 class="h5" id="session-section">Session</h2>
			<p class="text-muted small">Control how long your session stays active while you’re idle.</p>
			<form method="post" class="row g-2 align-items-end">
				<input type="hidden" name="csrf_token" value="<?php echo $csrfEnc; ?>" />
				<input type="hidden" name="action" value="set_session_timeout" />
				<div class="col-12 col-sm-6">
					<label class="form-label small" for="timeout_seconds">Session timeout</label>
					<select id="timeout_seconds" name="timeout_seconds" class="form-select form-select-sm">
						<option value="1800" <?php echo $sessionTimeoutSeconds === 1800 ? 'selected' : ''; ?>>30 minutes</option>
						<option value="3600" <?php echo $sessionTimeoutSeconds === 3600 ? 'selected' : ''; ?>>60 minutes</option>
						<option value="900" <?php echo $sessionTimeoutSeconds === 900 ? 'selected' : ''; ?>>15 minutes</option>
						<option value="0" <?php echo $sessionTimeoutSeconds === 0 ? 'selected' : ''; ?>>No timeout</option>
					</select>
				</div>
				<div class="col-12 col-sm-6 d-grid">
					<button type="submit" class="btn btn-primary btn-sm">Save</button>
				</div>
			</form>
		</div>
	</div>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-4">
			<h2 class="h5" id="passkeys-section">Passkeys</h2>
			<p class="text-muted small">Security keys or device biometrics. HTTPS required in production (localhost is OK for dev).</p>
			<button type="button" class="btn btn-primary btn-sm mb-3" id="addPasskey" <?php echo $securityBaselineSafe ? '' : 'disabled'; ?>><i class="bi bi-key me-1" aria-hidden="true"></i>Add passkey</button>
			<ul class="list-group list-group-flush">
				<?php foreach ($creds as $c): ?>
					<li class="list-group-item d-flex justify-content-between align-items-center px-0">
						<span><?php echo htmlspecialchars((string) ($c['FriendlyName'] ?? 'Passkey'), ENT_QUOTES, 'UTF-8'); ?>
							<span class="text-muted small ms-2"><?php echo htmlspecialchars((string) ($c['CreatedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
						</span>
						<form method="post" class="m-0" onsubmit="return confirm('Remove this passkey?');">
							<input type="hidden" name="csrf_token" value="<?php echo $csrfEnc; ?>" />
							<input type="hidden" name="action" value="delete_passkey" />
							<input type="hidden" name="cred_id" value="<?php echo (int) ($c['ID'] ?? 0); ?>" />
							<button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
						</form>
					</li>
				<?php endforeach; ?>
				<?php if ($creds === []): ?>
					<li class="list-group-item px-0 text-muted small">No passkeys yet.</li>
				<?php endif; ?>
			</ul>
		</div>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
<script>
(function () {
	var csrf = <?php echo json_encode((string) ($_SESSION['csrf_webauthn'] ?? ''), JSON_THROW_ON_ERROR); ?>;
	var btn = document.getElementById('addPasskey');
	if (!btn || !window.PublicKeyCredential) return;
	function b64urlToBuf(s) {
		if (!s) return null;
		s = s.replace(/-/g, '+').replace(/_/g, '/');
		var pad = s.length % 4; if (pad) s += '===='.slice(pad);
		var bin = atob(s);
		var buf = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
		return buf.buffer;
	}
	function bufToB64url(buf) {
		if (buf === null || buf === undefined) return null;
		var u8 = buf instanceof Uint8Array ? buf : new Uint8Array(buf);
		var bin = '';
		for (var i = 0; i < u8.length; i++) bin += String.fromCharCode(u8[i]);
		var b64 = btoa(bin);
		return b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
	}
	function credentialToPayload(cred) {
		// Prefer native toJSON when available, but Dashlane-style wrappers may
		// not provide it. In that case, build the payload explicitly.
		if (cred && typeof cred.toJSON === 'function') {
			try {
				return cred.toJSON();
			} catch (e) {}
		}

		var resp = cred && cred.response ? cred.response : null;
		var clientDataJSON = resp && resp.clientDataJSON ? resp.clientDataJSON : null;
		var attestationObject = resp && resp.attestationObject ? resp.attestationObject : null;

		var rawIdB64url = cred && cred.rawId ? bufToB64url(cred.rawId) : null;
		var id = (cred && typeof cred.id === 'string' && cred.id) ? cred.id : rawIdB64url;

		return {
			id: id,
			rawId: rawIdB64url,
			type: cred && cred.type ? cred.type : 'public-key',
			response: {
				clientDataJSON: bufToB64url(clientDataJSON),
				attestationObject: bufToB64url(attestationObject)
			}
		};
	}
	function stripNulls(value) {
		if (value === null || value === undefined) return undefined;
		if (Array.isArray(value)) {
			var out = [];
			for (var i = 0; i < value.length; i++) {
				var v = stripNulls(value[i]);
				if (v !== undefined) out.push(v);
			}
			return out;
		}
		if (typeof value === 'object') {
			var obj = {};
			Object.keys(value).forEach(function (k) {
				var v = stripNulls(value[k]);
				if (v !== undefined) obj[k] = v;
			});
			return obj;
		}
		return value;
	}
	btn.addEventListener('click', async function () {
		btn.disabled = true;
		try {
			if (!window.isSecureContext) {
				throw new Error('WebAuthn requires a secure context (HTTPS or localhost).');
			}
			if (!window.PublicKeyCredential) {
				throw new Error('WebAuthn is not available in this browser context.');
			}
			var r = await fetch('../json/webauthn-register-options.php?csrf=' + encodeURIComponent(csrf), { credentials: 'same-origin' });
			if (!r.ok) {
				throw new Error('register-options HTTP ' + r.status);
			}
			var pack = await r.json();
			if (!pack.publicKey) throw new Error('register-options missing publicKey');
			var options = pack.publicKey;
			options.challenge = b64urlToBuf(options.challenge);
			if (options.user && options.user.id) options.user.id = b64urlToBuf(options.user.id);
			if (options.excludeCredentials) {
				options.excludeCredentials = options.excludeCredentials.map(function (c) {
					var o = Object.assign({}, c);
					if (o.id) o.id = b64urlToBuf(o.id);
					return o;
				});
			}
			var cred = await navigator.credentials.create({ publicKey: options });
			var payload = credentialToPayload(cred);
			payload = stripNulls(payload) || {};
			var body = JSON.stringify({ csrf: csrf, credential: payload, friendlyName: 'Passkey' });
			var vr = await fetch('../json/webauthn-register-verify.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: body
			});
			if (!vr.ok) {
				var msg = 'register-verify HTTP ' + vr.status;
				try {
					var out = await vr.json();
					if (out && typeof out === 'object') {
						if (out.error) msg += ' (' + out.error + ')';
						if (out.detail) msg += ': ' + out.detail;
					}
				} catch (e) {}
				throw new Error(msg);
			}
			var out = await vr.json();
			if (out && out.ok) { window.location.reload(); return; }
			throw new Error(out && out.error ? out.error : 'verify_failed');
		} catch (e) {
			console.error('Passkey registration failed', e);
			var detail = (e && typeof e === 'object' && 'message' in e && e.message) ? e.message : String(e);
			alert('Could not register passkey. ' + detail);
		}
		btn.disabled = false;
	});
})();
</script>
</body>
</html>
