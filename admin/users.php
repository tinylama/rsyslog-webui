<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_admin();

$me = auth_current_user();
$myId = (int) ($me['id'] ?? 0);
$displayName = is_array($me) ? (string) ($me['display_name'] ?? '') : '';
$navAdminActive = 'users';

if (!isset($_SESSION['csrf_admin']) || !is_string($_SESSION['csrf_admin']) || strlen($_SESSION['csrf_admin']) < 32) {
	$_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_admin'];

$error = '';
$success = '';
$pdo = auth_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedCsrf = $_POST['csrf_token'] ?? '';
	if (!is_string($postedCsrf) || !hash_equals($_SESSION['csrf_admin'], $postedCsrf)) {
		$error = 'Invalid session. Refresh and try again.';
	} else {
		$action = (string) ($_POST['action'] ?? '');
		$targetId = (int) ($_POST['user_id'] ?? 0);

		if ($action !== 'create' && $targetId < 1) {
			$error = 'Invalid user.';
		} elseif ($action === 'create') {
			$username = trim((string) ($_POST['username'] ?? ''));
			$password = (string) ($_POST['password'] ?? '');
			$role = (string) ($_POST['role'] ?? 'user');
			$email = trim((string) ($_POST['email'] ?? ''));
			if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$error = 'Invalid email.';
			} elseif ($username === '' || $password === '') {
				$error = 'Username and password are required.';
			} elseif (strlen($password) < 8) {
				$error = 'Password must be at least 8 characters.';
			} elseif (!in_array($role, ['admin', 'user'], true)) {
				$error = 'Invalid role.';
			} else {
				$pdoCreate = auth_db();
				try {
					$pdoCreate->beginTransaction();
					$hash = password_hash($password, PASSWORD_BCRYPT);
					$stmt = $pdoCreate->prepare(
						'INSERT INTO Users (Username, PasswordHash, Email) VALUES (:u, :p, NULLIF(:e, \'\'))'
					);
					$stmt->execute([':u' => $username, ':p' => $hash, ':e' => $email]);
					$userId = (int) $pdoCreate->lastInsertId();
					$stmtRole = $pdoCreate->prepare('SELECT ID FROM Roles WHERE Name = :r LIMIT 1');
					$stmtRole->execute([':r' => $role]);
					$roleId = (int) $stmtRole->fetchColumn();
					if ($roleId < 1) {
						throw new RuntimeException('role missing');
					}
					$stmtMap = $pdoCreate->prepare('INSERT INTO UserRoles (UserID, RoleID) VALUES (:uid, :rid)');
					$stmtMap->execute([':uid' => $userId, ':rid' => $roleId]);
					$pdoCreate->commit();
					$success = 'User created.';
				} catch (Throwable $e) {
					if ($pdoCreate->inTransaction()) {
						$pdoCreate->rollBack();
					}
					$error = 'Could not create user (duplicate username?)';
				}
			}
		} elseif ($action === 'update_username') {
			$targetUsername = trim((string) ($_POST['username_new'] ?? ''));
			if ($targetUsername === '') {
				$error = 'Username is required.';
			} elseif (mb_strlen($targetUsername) < 3 || mb_strlen($targetUsername) > 100) {
				$error = 'Username must be between 3 and 100 characters.';
			} elseif (preg_match('/\s/', $targetUsername) === 1) {
				$error = 'Username cannot contain spaces.';
			} else {
				$uStmt = $pdo->prepare('SELECT Username, ExternalProvider FROM Users WHERE ID = :id LIMIT 1');
				$uStmt->execute([':id' => $targetId]);
				$uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
				if ($uRow === false) {
					$error = 'User not found.';
				} elseif (!empty($uRow['ExternalProvider'])) {
					$error = 'Username for Microsoft (Entra) accounts is managed by Microsoft sign-in.';
				} else {
					$dup = $pdo->prepare('SELECT 1 FROM Users WHERE Username = :u AND ID <> :id LIMIT 1');
					$dup->execute([':u' => $targetUsername, ':id' => $targetId]);
					if ($dup->fetchColumn()) {
						$error = 'Username already exists.';
					} else {
						$pdo->prepare('UPDATE Users SET Username = :u WHERE ID = :id')->execute([
							':u' => $targetUsername,
							':id' => $targetId,
						]);

						if ($targetId === $myId) {
							$_SESSION['display_name'] = $targetUsername;
						}

						$success = 'Username updated.';
					}
				}
			}
		} elseif ($action === 'reset_password') {
			$newPass = (string) ($_POST['new_password'] ?? '');
			if (strlen($newPass) < 8) {
				$error = 'Password must be at least 8 characters.';
			} else {
				$uStmt = $pdo->prepare('SELECT ExternalProvider FROM Users WHERE ID = :id LIMIT 1');
				$uStmt->execute([':id' => $targetId]);
				$ext = $uStmt->fetchColumn();
				$ext = is_string($ext) ? trim($ext) : '';
				if ($ext !== '') {
					$error = 'Password for Microsoft (Entra) accounts is managed by Microsoft sign-in.';
				} else {
					$hash = password_hash($newPass, PASSWORD_BCRYPT);
					$stmt = $pdo->prepare('UPDATE Users SET PasswordHash = :h WHERE ID = :id');
					$stmt->execute([':h' => $hash, ':id' => $targetId]);
					if ($stmt->rowCount() < 1) {
						$error = 'User not found.';
					} else {
						$success = 'Password updated.';
					}
				}
			}
		} elseif ($action === 'update_role') {
			$role = (string) ($_POST['role'] ?? '');
			if (!in_array($role, ['admin', 'user'], true)) {
				$error = 'Invalid role.';
			} else {
				$wasAdmin = auth_user_is_admin($pdo, $targetId);
				$adminCount = auth_admin_user_count($pdo);
				if ($wasAdmin && $role === 'user' && $adminCount <= 1) {
					$error = 'Cannot remove the last admin.';
				} else {
					$stmtRole = $pdo->prepare('SELECT ID FROM Roles WHERE Name = :r LIMIT 1');
					$stmtRole->execute([':r' => $role]);
					$roleId = (int) $stmtRole->fetchColumn();
					if ($roleId < 1) {
						$error = 'Role missing.';
					} else {
						$pdo->prepare('DELETE FROM UserRoles WHERE UserID = :id')->execute([':id' => $targetId]);
						$pdo->prepare('INSERT INTO UserRoles (UserID, RoleID) VALUES (:uid, :rid)')->execute([
							':uid' => $targetId,
							':rid' => $roleId,
						]);
						if ($targetId === $myId) {
							$_SESSION['user_role'] = $role;
						}
						$success = 'Role updated.';
					}
				}
			}
		} elseif ($action === 'delete') {
			if ($targetId === $myId) {
				$error = 'You cannot delete your own account.';
			} elseif (auth_user_is_admin($pdo, $targetId) && auth_admin_user_count($pdo) <= 1) {
				$error = 'Cannot delete the last admin.';
			} else {
				$stmt = $pdo->prepare('DELETE FROM Users WHERE ID = :id');
				$stmt->execute([':id' => $targetId]);
				if ($stmt->rowCount() < 1) {
					$error = 'User not found.';
				} else {
					$success = 'User deleted.';
				}
			}
		} else {
			$error = 'Unknown action.';
		}
	}
}

$stmtList = $pdo->query(
	'SELECT u.ID, u.Username, r.Name AS RoleName, u.ExternalProvider
	 FROM Users u
	 JOIN UserRoles ur ON ur.UserID = u.ID
	 JOIN Roles r ON r.ID = ur.RoleID
	 ORDER BY u.Username'
);
$users = $stmtList ? $stmtList->fetchAll(PDO::FETCH_ASSOC) : [];
$userCount = count($users);
$siteTitle = htmlspecialchars($site_name ?? 'System Logs', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Admin — <?php echo $siteTitle; ?></title>
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
			<h1 class="h3 mb-1">Directory</h1>
			<p class="text-muted mb-0 small">Local passwords and Microsoft Entra sign-ins. Assign admin in-app or map an Entra group to admin (see README).</p>
		</div>
		<div class="col-12 col-md-4">
			<div class="card border-0 bg-body-secondary h-100">
				<div class="card-body d-flex align-items-center justify-content-between">
					<span class="text-muted small">Accounts</span>
					<span class="fs-4 fw-semibold"><?php echo (int) $userCount; ?></span>
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
		<div class="card-body p-0">
			<div class="table-responsive">
				<table class="table table-hover table-striped align-middle mb-0 small">
			<thead class="table-light">
				<tr>
					<th class="ps-3">Username</th>
					<th>Source</th>
					<th style="min-width: 220px">Role</th>
					<th class="d-none d-xl-table-cell" style="min-width: 200px">New password</th>
					<th class="pe-3"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($users as $u):
					$uid = (int) $u['ID'];
					$isSelf = $uid === $myId;
					$isRowAdmin = (($u['RoleName'] ?? '') === 'admin');
					$adminCount = auth_admin_user_count($pdo);
					$onlyAdmin = $isRowAdmin && $adminCount <= 1;
					$ext = isset($u['ExternalProvider']) ? trim((string) $u['ExternalProvider']) : '';
					$sourceLabel = $ext !== '' ? 'Microsoft' : 'Local';
					$sourceClass = $ext !== '' ? 'text-primary' : 'text-secondary';
					?>
					<tr>
						<td class="ps-3">
							<div class="fw-medium"><?php echo htmlspecialchars((string) $u['Username'], ENT_QUOTES, 'UTF-8'); ?></div>
							<div class="mt-2">
								<form method="post" class="d-flex gap-1 align-items-center">
									<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
									<input type="hidden" name="action" value="update_username" />
									<input type="hidden" name="user_id" value="<?php echo $uid; ?>" />
									<input
										class="form-control form-control-sm"
										type="text"
										name="username_new"
										placeholder="<?php echo $ext !== '' ? 'Managed by Microsoft' : 'New username'; ?>"
										autocomplete="off"
										value=""
										<?php echo $ext !== '' ? 'disabled' : ''; ?>
										<?php echo $ext === '' ? 'required' : ''; ?>
									/>
									<button type="submit" class="btn btn-sm btn-outline-secondary"<?php echo $ext !== '' ? ' disabled' : ''; ?>>Change</button>
								</form>
								<?php if ($ext !== ''): ?>
									<div class="form-text small mb-0">SSO-managed usernames can be updated in Microsoft sign-in.</div>
								<?php endif; ?>
							</div>
						</td>
						<td><span class="<?php echo htmlspecialchars($sourceClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
						<td>
							<div class="d-flex flex-wrap align-items-center gap-2">
								<span class="badge <?php echo $isRowAdmin ? 'bg-danger' : 'bg-secondary'; ?>"><?php echo htmlspecialchars((string) $u['RoleName'], ENT_QUOTES, 'UTF-8'); ?></span>
								<form method="post" class="d-flex flex-wrap gap-1 align-items-center">
									<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
									<input type="hidden" name="action" value="update_role" />
									<input type="hidden" name="user_id" value="<?php echo $uid; ?>" />
									<select name="role" class="form-select form-select-sm" style="width: auto; min-width: 5.5rem" aria-label="Change role">
										<option value="user" <?php echo !$isRowAdmin ? ' selected' : ''; ?>>user</option>
										<option value="admin" <?php echo $isRowAdmin ? ' selected' : ''; ?>>admin</option>
									</select>
									<button type="submit" class="btn btn-sm btn-outline-secondary"<?php echo ($onlyAdmin && $isRowAdmin) ? ' disabled title="Add another admin before demoting"' : ''; ?>>Apply</button>
								</form>
							</div>
							<?php if ($onlyAdmin && $isRowAdmin): ?>
								<div class="form-text small mb-0">Last admin — add another before demoting.</div>
							<?php endif; ?>
							<div class="d-xl-none mt-2">
								<form method="post" class="d-flex gap-1 align-items-center">
									<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
									<input type="hidden" name="action" value="reset_password" />
									<input type="hidden" name="user_id" value="<?php echo $uid; ?>" />
									<input class="form-control form-control-sm flex-grow-1" type="password" name="new_password" autocomplete="new-password" placeholder="New password" />
									<button type="submit" class="btn btn-sm btn-outline-primary">Set</button>
								</form>
							</div>
						</td>
						<td class="d-none d-xl-table-cell">
							<form method="post" class="row g-1 align-items-center flex-nowrap">
								<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
								<input type="hidden" name="action" value="reset_password" />
								<input type="hidden" name="user_id" value="<?php echo $uid; ?>" />
								<div class="col min-w-0">
									<input class="form-control form-control-sm" type="password" name="new_password" autocomplete="new-password" placeholder="Min 8 chars" />
								</div>
								<div class="col-auto">
									<button type="submit" class="btn btn-sm btn-outline-primary">Set</button>
								</div>
							</form>
						</td>
						<td class="pe-3 text-end">
							<?php if (!$isSelf): ?>
								<form method="post" class="d-inline" onsubmit="return confirm('Delete this user permanently?');">
									<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
									<input type="hidden" name="action" value="delete" />
									<input type="hidden" name="user_id" value="<?php echo $uid; ?>" />
									<button type="submit" class="btn btn-sm btn-outline-danger" <?php echo ($onlyAdmin && $isRowAdmin) ? 'disabled title="Cannot delete the last admin"' : ''; ?>>Delete</button>
								</form>
							<?php else: ?>
								<span class="text-muted small">You</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="card border-0 shadow-sm">
		<div class="card-body p-4">
			<h2 class="h5 mb-3">Add local user</h2>
			<div class="col-12 col-lg-8 col-xl-6 px-0">
				<form method="post" class="row g-3">
					<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
					<input type="hidden" name="action" value="create" />
					<div class="col-12">
						<label class="form-label" for="username">Username</label>
						<input class="form-control" id="username" name="username" type="text" autocomplete="username" required />
					</div>
					<div class="col-12">
						<label class="form-label" for="password">Password</label>
						<input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required minlength="8" />
					</div>
					<div class="col-12">
						<label class="form-label" for="email_new">Email <span class="text-muted fw-normal">(optional, for password reset + email verification)</span></label>
						<input class="form-control" id="email_new" name="email" type="email" autocomplete="off" />
					</div>
					<div class="col-12">
						<label class="form-label" for="role_new">Role</label>
						<select class="form-select" id="role_new" name="role">
							<option value="user">user</option>
							<option value="admin">admin</option>
						</select>
					</div>
					<div class="col-12">
						<button type="submit" class="btn btn-primary">Create user</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
