<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/graph-mail.php';

if (!auth_is_enabled()) {
	header('Location: /login.php', true, 302);
	exit;
}
auth_bootstrap_once();

$pending = auth_mfa_pending();
if ($pending === null) {
	header('Location: /login.php', true, 302);
	exit;
}
if (!empty($_SESSION['user_id'])) {
	header('Location: ' . $pending['next'], true, 302);
	exit;
}

if (!isset($_SESSION['csrf_mfa']) || !is_string($_SESSION['csrf_mfa']) || strlen($_SESSION['csrf_mfa']) < 32) {
	$_SESSION['csrf_mfa'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_mfa'];

$pdo = auth_db();
$uid = $pending['user_id'];
$row = auth_user_row_by_id($pdo, $uid);
$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$t = $_POST['csrf_token'] ?? '';
	if (!is_string($t) || !hash_equals($csrf, $t)) {
		$error = 'Invalid session. Refresh and try again.';
	} elseif (auth_mfa_attempts_exceeded()) {
		auth_log_signin_attempt($uid, (string) ($pending['username'] ?? ''), (string) ($pending['provider'] ?? 'local'), 'failed', 'mfa_locked', 'mfa_challenge');
		auth_mfa_clear_pending();
		header('Location: /login.php?err=' . rawurlencode('mfa_locked'), true, 302);
		exit;
	} else {
		$action = (string) ($_POST['action'] ?? '');
		if ($action === 'totp') {
			$code = trim((string) ($_POST['code'] ?? ''));
			if (auth_mfa_verify_totp($pdo, $uid, $code)) {
				auth_mfa_clear_pending();
				$dn = $pending['display_name'] ?? $pending['username'] ?? 'user';
				auth_establish_session($uid, $pending['role'], $dn, $pending['provider']);
				auth_log_signin_attempt($uid, (string) ($pending['username'] ?? $dn), (string) ($pending['provider'] ?? 'local'), 'allowed', 'mfa_totp_verified', 'mfa_challenge');
				header('Location: ' . $pending['next'], true, 302);
				exit;
			}
			auth_mfa_bump_attempts();
			auth_log_signin_attempt($uid, (string) ($pending['username'] ?? ''), (string) ($pending['provider'] ?? 'local'), 'failed', 'mfa_totp_invalid', 'mfa_challenge');
			$error = 'Invalid authenticator code.';
			// Email-based MFA was removed in favor of TOTP + passkeys.
			// Password reset and email-change verification use email codes elsewhere.
		}
	}
}

$hasTotp = $row && isset($row['MfaTotpSecret']) && is_string($row['MfaTotpSecret']) && trim($row['MfaTotpSecret']) !== '';
$hasWeb = auth_webauthn_credential_count($pdo, $uid) > 0;

$siteTitle = htmlspecialchars($site_name ?? 'System Logs', ENT_QUOTES, 'UTF-8');
$csrfEnc = htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Verify sign-in — <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
	<script>
	(function(){ try { if (localStorage.getItem('darkSwitch') === 'dark') document.documentElement.setAttribute('data-bs-theme','dark'); } catch(e){} })();
	</script>
</head>
<body class="bg-body-secondary">
<div class="app-shell py-4 py-md-5">
	<div class="row justify-content-center px-1">
		<div class="col-12 col-md-10 col-lg-7 col-xl-6">
			<div class="card shadow-sm border-0">
				<div class="card-body p-4 p-md-5">
					<h1 class="h4 mb-2"><i class="bi bi-shield-check text-primary me-2" aria-hidden="true"></i>Second step</h1>
					<p class="text-muted small mb-4">Complete sign-in with your enrolled MFA method.</p>
					<?php if ($error !== ''): ?>
						<div class="alert alert-danger small"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
					<?php endif; ?>
					<?php if ($info !== ''): ?>
						<div class="alert alert-info small"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></div>
					<?php endif; ?>

					<?php if ($hasWeb): ?>
					<div class="mb-4 pb-3 border-bottom">
						<h2 class="h6">Passkey</h2>
						<button type="button" class="btn btn-primary" id="btnPasskey"><i class="bi bi-key me-2" aria-hidden="true"></i>Use passkey</button>
						<p class="small text-muted mt-2 mb-0">Uses a security key or device PIN/biometrics.</p>
					</div>
					<?php endif; ?>

					<?php if ($hasTotp): ?>
					<div class="mb-4 pb-3 border-bottom">
						<h2 class="h6">Authenticator app</h2>
						<form method="post" class="row g-2 align-items-end">
							<input type="hidden" name="csrf_token" value="<?php echo $csrfEnc; ?>" />
							<input type="hidden" name="action" value="totp" />
							<div class="col-8 col-sm-6">
								<label class="form-label small" for="totpCode">6-digit code</label>
								<input id="totpCode" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required />
							</div>
							<div class="col-auto">
								<button type="submit" class="btn btn-outline-primary">Verify</button>
							</div>
						</form>
					</div>
					<?php endif; ?>

					<?php if (!$hasTotp && !$hasWeb): ?>
					<div class="alert alert-warning small mb-0">No MFA methods are available for this account. Ask an administrator to reset MFA or enroll a method in Account security.</div>
					<?php endif; ?>

					<p class="mt-4 mb-0"><a href="../logout.php" class="small">Cancel and sign out</a></p>
				</div>
			</div>
		</div>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
<?php if ($hasWeb): ?>
<script>
(function () {
	var btn = document.getElementById('btnPasskey');
	if (!btn || !window.PublicKeyCredential) return;
	function b64urlToBuf(s) {
		if (!s) return null;
		s = String(s).replace(/-/g, '+').replace(/_/g, '/');
		var pad = s.length % 4; if (pad) s += '===='.slice(pad);
		var bin = atob(s);
		var buf = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
		return buf.buffer;
	}
	btn.addEventListener('click', async function () {
		btn.disabled = true;
		try {
			var r = await fetch('../json/webauthn-assert-options.php', { credentials: 'same-origin' });
			if (!r.ok) {
				throw new Error('assert-options HTTP ' + r.status);
			}
			var pack = await r.json();
			if (!pack || !pack.publicKey) throw new Error('opts');
			var options = pack.publicKey;
			options.challenge = b64urlToBuf(options.challenge);
			if (options.allowCredentials) {
				options.allowCredentials = options.allowCredentials.map(function (c) {
					var o = Object.assign({}, c);
					if (o.id) o.id = b64urlToBuf(o.id);
					return o;
				});
			}
			var cred = await navigator.credentials.get({ publicKey: options });
			if (!cred || cred.type !== 'public-key') throw new Error('cred');
			var payload = cred.toJSON ? cred.toJSON() : null;
			if (!payload) throw new Error('toJSON');
			var vr = await fetch('../json/webauthn-assert-verify.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify(payload)
			});
			var out = await vr.json();
			if (out && out.ok && out.redirect) {
				window.location = out.redirect;
				return;
			}
			throw new Error(out && out.error ? out.error : 'verify_failed');
		} catch (e) {
			console.error('Passkey sign-in failed', e);
			var detail = e && typeof e === 'object' && 'message' in e ? e.message : String(e);
			alert('Passkey sign-in failed. ' + detail);
		}
		btn.disabled = false;
	});
})();
</script>
<?php endif; ?>
</body>
</html>
