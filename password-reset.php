<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/graph-mail.php';

$siteTitle = htmlspecialchars($site_name ?? 'System Logs', ENT_QUOTES, 'UTF-8');
$currentUser = auth_is_enabled() ? auth_current_user() : null;
$signedIn = auth_is_enabled() && $currentUser !== null;

// Password reset via email verification codes.
// Uses the same email-code challenge table as prior email MFA.
if (!isset($_SESSION['csrf_password_reset']) || !is_string($_SESSION['csrf_password_reset']) || strlen($_SESSION['csrf_password_reset']) < 32) {
	$_SESSION['csrf_password_reset'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf_password_reset'];

$pdo = auth_db();
$error = '';
$success = '';

$pendingUserId = isset($_SESSION['password_reset_pending_user_id']) ? (int) $_SESSION['password_reset_pending_user_id'] : 0;
$pendingEmail = isset($_SESSION['password_reset_pending_email']) && is_string($_SESSION['password_reset_pending_email'])
	? trim($_SESSION['password_reset_pending_email']) : '';
$stepConfirm = $pendingUserId > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedCsrf = $_POST['csrf_token'] ?? '';
	if (!is_string($postedCsrf) || !hash_equals($csrf, $postedCsrf)) {
		$error = 'Invalid session. Refresh and try again.';
	} else {
		$action = (string) ($_POST['action'] ?? '');
		if ($action === 'request_reset') {
			$email = trim((string) ($_POST['email'] ?? ''));
			if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$error = 'Enter a valid email address.';
			} else {
				if (!graph_mail_is_configured()) {
					// If email sending isn't configured, password reset can't complete.
					$error = 'Password reset via email is not configured. Ask an administrator to enable Graph mail (Mail.Send).';
					unset($_SESSION['password_reset_pending_user_id'], $_SESSION['password_reset_pending_email']);
					$pendingUserId = 0;
					$pendingEmail = '';
					$stepConfirm = false;
				} else {
				// Always show the same UX to avoid leaking whether the email exists.
				$stmt = $pdo->prepare(
					'SELECT ID FROM Users
					 WHERE Email = :e AND (ExternalProvider IS NULL OR ExternalProvider = \'\')
					 LIMIT 1'
				);
				$stmt->execute([':e' => $email]);
				$uid = (int) $stmt->fetchColumn();

				$_SESSION['password_reset_pending_user_id'] = $uid;
				$_SESSION['password_reset_pending_email'] = $email;
				$stepConfirm = true;

				if ($uid > 0) {
						// Send verification code (best-effort; even if send fails, we still show a generic message).
						auth_email_code_challenge_start($pdo, $uid, $email);
				}

				$success = 'If the account exists, a verification code has been sent. Enter it below to set a new password.';
				}
			}
		} elseif ($action === 'confirm_reset') {
			$codeRaw = trim((string) ($_POST['code'] ?? ''));
			$code = preg_replace('/\D+/', '', $codeRaw) ?? '';

			$newPass = (string) ($_POST['new_password'] ?? '');
			$newPass2 = (string) ($_POST['new_password_confirm'] ?? '');

			if (!preg_match('/^\d{6}$/', $code)) {
				$error = 'Enter the 6-digit verification code.';
			} elseif ($newPass === '' || $newPass2 === '' || $newPass !== $newPass2) {
				$error = 'Passwords do not match.';
			} elseif (strlen($newPass) < 8) {
				$error = 'Password must be at least 8 characters.';
			} elseif ($pendingUserId < 1) {
				$error = 'Invalid or expired verification code.';
			} else {
				if (!auth_email_code_challenge_verify($pdo, $pendingUserId, $code)) {
					$error = 'Invalid or expired verification code.';
				} else {
					// SSO-managed accounts must not be able to set local passwords via email.
					$uStmt = $pdo->prepare('SELECT ExternalProvider FROM Users WHERE ID = :id LIMIT 1');
					$uStmt->execute([':id' => $pendingUserId]);
					$ext = $uStmt->fetchColumn();
					$ext = is_string($ext) ? trim($ext) : '';
					if ($ext !== '') {
						$error = 'This account is managed by Microsoft sign-in. Password reset via local password is not available.';
						unset($_SESSION['password_reset_pending_user_id'], $_SESSION['password_reset_pending_email']);
						$pendingUserId = 0;
						$pendingEmail = '';
						$stepConfirm = false;
					} else {
						$hash = password_hash($newPass, PASSWORD_BCRYPT);
						$pdo->prepare('UPDATE Users SET PasswordHash = :h WHERE ID = :id')->execute([
							':h' => $hash,
							':id' => $pendingUserId,
						]);
						unset($_SESSION['password_reset_pending_user_id'], $_SESSION['password_reset_pending_email']);
						$pendingUserId = 0;
						$pendingEmail = '';
						$stepConfirm = false;
						$success = 'Password updated. You can sign in now.';
					}
				}
			}
		} elseif ($action === 'cancel_reset') {
			unset($_SESSION['password_reset_pending_user_id'], $_SESSION['password_reset_pending_email']);
			$pendingUserId = 0;
			$pendingEmail = '';
			$stepConfirm = false;
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Password reset — <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="css/layout.css" rel="stylesheet">
	<link href="css/dark-mode.css" rel="stylesheet">
	<script>
	(function () {
		try {
			var v = localStorage.getItem('darkSwitch');
			var isDark = v !== null && v === 'dark';
				function apply() {
					if (isDark) {
						document.documentElement.setAttribute('data-bs-theme', 'dark');
						if (document.body) document.body.setAttribute('data-theme', 'dark');
					} else {
						document.documentElement.setAttribute('data-bs-theme', 'light');
						if (document.body) document.body.removeAttribute('data-theme');
					}
				}

				// Apply immediately for `html` (exists early), and for `body` once parsed.
				document.documentElement.setAttribute('data-bs-theme', isDark ? 'dark' : 'light');
				window.addEventListener('DOMContentLoaded', apply, { once: true });
		} catch (e) {}
	})();
	</script>
</head>
<body class="bg-body-secondary">
<div class="app-shell py-4 py-md-5">
	<div class="row justify-content-center px-1">
		<div class="col-12 col-sm-10 col-md-8 col-lg-5 col-xl-4">
			<div class="card shadow-sm border-0">
				<div class="card-body p-4 p-md-5">
					<?php if ($signedIn): ?>
						<div class="d-flex justify-content-end mb-2">
							<a href="logout.php" class="small text-muted">Sign out</a>
						</div>
					<?php endif; ?>
					<div class="d-flex align-items-start gap-3 mb-3">
						<span class="rounded-circle bg-primary-subtle text-primary d-inline-flex p-3"><i class="bi bi-shield-check fs-4" aria-hidden="true"></i></span>
						<div>
							<h1 class="h4 mb-1">Password reset</h1>
							<p class="text-muted small mb-0">Use your email for a verification code.</p>
						</div>
					</div>

					<?php if ($error !== ''): ?>
						<div class="alert alert-danger small" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
					<?php endif; ?>
					<?php if ($success !== ''): ?>
						<div class="alert alert-success small" role="alert"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
					<?php endif; ?>

					<?php if ($stepConfirm): ?>
						<p class="text-muted small">Enter the 6-digit code we emailed to <strong><?php echo htmlspecialchars($pendingEmail, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
						<form method="post" action="password-reset.php">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
							<input type="hidden" name="action" value="confirm_reset" />
							<div class="mb-3">
								<label class="form-label small" for="code">6-digit code</label>
								<input id="code" name="code" class="form-control form-control-lg" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required />
							</div>
							<div class="mb-3">
								<label class="form-label small" for="newPass">New password</label>
								<input id="newPass" name="new_password" type="password" class="form-control" autocomplete="new-password" required minlength="8" />
							</div>
							<div class="mb-4">
								<label class="form-label small" for="newPass2">Confirm new password</label>
								<input id="newPass2" name="new_password_confirm" type="password" class="form-control" autocomplete="new-password" required minlength="8" />
							</div>
							<button type="submit" class="btn btn-primary btn-lg w-100">Set new password</button>
						</form>
						<form method="post" action="password-reset.php" class="mt-3">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
							<input type="hidden" name="action" value="cancel_reset" />
							<button type="submit" class="btn btn-link text-decoration-none w-100">Cancel</button>
						</form>
					<?php else: ?>
						<form method="post" action="password-reset.php">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
							<input type="hidden" name="action" value="request_reset" />
							<div class="mb-3">
								<label class="form-label" for="email">Account email</label>
								<input id="email" name="email" class="form-control form-control-lg" type="email" autocomplete="email" required />
							</div>

							<?php if (!graph_mail_is_configured()): ?>
								<div class="alert alert-warning small mb-3" role="alert">
									Password reset via email isn’t configured yet. Ask an administrator to enable Microsoft Entra + Graph mail (Mail.Send).
								</div>
								<button type="submit" class="btn btn-primary btn-lg w-100" disabled>Send code</button>
							<?php else: ?>
								<button type="submit" class="btn btn-primary btn-lg w-100">Send verification code</button>
							<?php endif; ?>
						</form>

						<div class="text-center mt-3">
							<?php if ($signedIn): ?>
								<a href="/" class="small text-muted">Back to log viewer</a>
							<?php else: ?>
								<a href="login.php" class="small text-muted">Back to sign in</a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>
</body>
</html>

