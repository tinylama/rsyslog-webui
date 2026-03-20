<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/azure-oidc.php';

if (auth_is_enabled()) {
	auth_bootstrap_once();
}

$setupMode = false;
if (auth_is_enabled()) {
	$pdo = auth_db();
	$userCount = (int) $pdo->query('SELECT COUNT(*) FROM Users')->fetchColumn();
	$adminCount = auth_admin_user_count($pdo);
	$setupMode = $userCount === 0 || $adminCount === 0;
}

$nextRaw = $_GET['next'] ?? '/';
$nextRaw = is_string($nextRaw) ? $nextRaw : '/';
$next = auth_safe_next($nextRaw);

if (auth_is_enabled() && auth_current_user() !== null) {
	header('Location: ' . $next);
	exit;
}

if (!isset($_SESSION['csrf_login']) || !is_string($_SESSION['csrf_login']) || strlen($_SESSION['csrf_login']) < 32) {
	$_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_login'];

$errGet = $_GET['err'] ?? '';
$errBanner = is_string($errGet) && $errGet !== '' ? $errGet : '';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedNext = $_POST['next'] ?? '/';
	$postedNext = is_string($postedNext) ? $postedNext : '/';
	$next = auth_safe_next($postedNext);

	$postedCsrf = $_POST['csrf_token'] ?? '';
	if (!is_string($postedCsrf) || !hash_equals($_SESSION['csrf_login'], $postedCsrf)) {
		$error = 'Invalid session. Please refresh the page and try again.';
	} else {
		$username = trim($_POST['username'] ?? '');
		$password = $_POST['password'] ?? '';

		if ($username === '' || $password === '') {
			$error = 'Username and password are required.';
		} else if ($setupMode) {
			// First-run admin setup. No hardcoded default admin.
			// Create an admin account, then sign the user in.
			if (mb_strlen($username) < 3) {
				$error = 'Username must be at least 3 characters.';
			} else if (mb_strlen($password) < 8) {
				$error = 'Password must be at least 8 characters.';
			} else {
				try {
					$pdo = auth_db();
					$existsStmt = $pdo->prepare('SELECT 1 FROM Users WHERE Username = :u LIMIT 1');
					$existsStmt->execute([':u' => $username]);
					if ($existsStmt->fetchColumn()) {
						$error = 'That username is already taken.';
					} else {
						$hash = password_hash($password, PASSWORD_BCRYPT);
						$ins = $pdo->prepare('INSERT INTO Users (Username, PasswordHash) VALUES (:u, :p)');
						$ins->execute([':u' => $username, ':p' => $hash]);
						$userId = (int) $pdo->lastInsertId();

						$adminRoleId = (int) $pdo->query("SELECT ID FROM Roles WHERE Name = 'admin' LIMIT 1")->fetchColumn();
						if ($adminRoleId <= 0) {
							throw new RuntimeException('admin_role_missing');
						}

						$pdo->prepare('INSERT IGNORE INTO UserRoles (UserID, RoleID) VALUES (:uid, :rid)')
							->execute([':uid' => $userId, ':rid' => $adminRoleId]);

						auth_establish_session($userId, 'admin', $username, 'local');
						$_SESSION['csrf_login'] = bin2hex(random_bytes(32));
						header('Location: ' . $next);
						exit;
					}
				} catch (Throwable $e) {
					$error = 'Could not create the first admin account. Please try again.';
				}
			}
		} else {
			$pdo = auth_db();
			$stmt = $pdo->prepare(
				"SELECT u.ID AS id, u.PasswordHash AS password_hash, u.ExternalProvider AS ext_provider,
					u.Email, u.MfaTotpSecret, u.MfaEmailEnabled, u.MfaRequired,
					r.Name AS role
				 FROM Users u
				 JOIN UserRoles ur ON ur.UserID = u.ID
				 JOIN Roles r ON r.ID = ur.RoleID
				 WHERE u.Username = :u
				 LIMIT 1"
			);
			$stmt->execute([':u' => $username]);
			$user = $stmt->fetch();

			if ($user && !empty($user['ext_provider'])) {
				$error = 'This account signs in with Microsoft. Use “Sign in with Microsoft” below.';
			} elseif ($user && password_verify($password, $user['password_hash'])) {
				$uid = (int) $user['id'];
				$role = $user['role'] ?? 'user';
				if (auth_user_requires_mfa($pdo, $uid, $user)) {
					auth_mfa_set_pending($uid, is_string($role) ? $role : 'user', $username, $username, 'local', $next);
					$_SESSION['csrf_login'] = bin2hex(random_bytes(32));
					header('Location: mfa/challenge.php', true, 302);
					exit;
				}
				auth_establish_session($uid, is_string($role) ? $role : 'user', $username, null);
				$_SESSION['csrf_login'] = bin2hex(random_bytes(32));
				header('Location: ' . $next);
				exit;
			} else {
				$error = 'Invalid username or password.';
			}
		}
	}
}

$siteTitle = htmlspecialchars($site_name ?? 'System Logs', ENT_QUOTES, 'UTF-8');
$azureReady = auth_is_enabled() && auth_azure_enabled() && auth_azure_is_configured();
$nextEnc = htmlspecialchars($next, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Sign in — <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="css/layout.css" rel="stylesheet">
	<link href="css/custom.css" rel="stylesheet">
	<link href="css/dark-mode.css" rel="stylesheet">
	<script>
	(function(){
		try {
			if (localStorage.getItem('darkSwitch') === 'dark') {
				document.documentElement.setAttribute('data-bs-theme', 'dark');
			}
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
					<div class="d-flex align-items-start gap-3 mb-3">
						<span class="rounded-circle bg-primary-subtle text-primary d-inline-flex p-3"><i class="bi bi-shield-lock fs-4" aria-hidden="true"></i></span>
						<div>
							<h1 class="h4 mb-1"><?php echo $setupMode ? 'First login setup' : $siteTitle; ?></h1>
							<p class="text-muted small mb-0">
								<?php echo $setupMode ? 'Create the first admin account to access system logs.' : 'Sign in to view system logs.'; ?>
							</p>
						</div>
					</div>
					<div class="form-check form-switch mb-3">
						<input class="form-check-input" type="checkbox" id="darkSwitch" aria-label="Dark mode">
						<label class="form-check-label small" for="darkSwitch">Dark mode</label>
					</div>

					<?php if ($errBanner !== ''): ?>
						<div class="alert alert-warning small" role="alert"><?php echo htmlspecialchars($errBanner, ENT_QUOTES, 'UTF-8'); ?></div>
					<?php endif; ?>
					<?php if ($error !== ''): ?>
						<div class="alert alert-danger small" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
					<?php endif; ?>

					<?php if ($azureReady && !$setupMode): ?>
						<a class="btn btn-dark w-100 py-2 mb-3 fw-semibold" href="auth/azure/start.php?next=<?php echo urlencode($next); ?>"><i class="bi bi-microsoft me-2" aria-hidden="true"></i>Sign in with Microsoft</a>
						<p class="text-center text-muted small text-uppercase mb-3">or use a local account</p>
					<?php endif; ?>

					<form method="post" action="login.php">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
						<input type="hidden" name="next" value="<?php echo $nextEnc; ?>" />
						<div class="mb-3">
							<label class="form-label" for="username">Username</label>
							<input id="username" class="form-control form-control-lg" type="text" name="username" autocomplete="username" />
						</div>
						<div class="mb-4">
							<label class="form-label" for="password">Password</label>
							<div class="input-group input-group-lg">
								<input id="password" class="form-control" type="password" name="password" autocomplete="current-password" />
								<button class="btn btn-outline-secondary" type="button" id="togglePw" title="Show password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
							</div>
						</div>
						<button id="loginBtn" type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Sign in</button>
					</form>
					<div class="text-center mt-3">
						<a href="password-reset.php" class="small text-muted">Forgot password?</a>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/dark-mode-switch.js"></script>
<script>
(function () {
	var pw = document.getElementById('password');
	var btn = document.getElementById('togglePw');
	if (!pw || !btn) return;
	btn.addEventListener('click', function () {
		var show = pw.type === 'password';
		pw.type = show ? 'text' : 'password';
		btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
		btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
	});
})();
</script>
</body>
</html>
