<?php
/**
 * Playwright E2E only: establish an admin session or remove e2e_* users using a per-run token file.
 *
 * Gated by Docker dev detection + RSYSLOG_E2E_ENABLED=1. Returns 404 when disabled so the
 * endpoint is not discoverable in production-like stacks.
 */
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_is_enabled()) {
	http_response_code(404);
	echo json_encode(['error' => 'not_found']);
	exit;
}

if (!auth_e2e_bootstrap_feature_enabled()) {
	http_response_code(404);
	echo json_encode(['error' => 'not_found']);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	http_response_code(405);
	header('Allow: POST');
	echo json_encode(['error' => 'method_not_allowed']);
	exit;
}

$token = $_POST['token'] ?? '';
if (!is_string($token)) {
	$token = '';
}
$expected = auth_e2e_read_expected_bootstrap_token();
if ($expected === '' || !hash_equals($expected, $token)) {
	http_response_code(403);
	echo json_encode(['error' => 'forbidden']);
	exit;
}

$action = isset($_POST['action']) ? trim((string) $_POST['action']) : 'session';
if ($action === '') {
	$action = 'session';
}

auth_bootstrap_once();
$pdo = auth_db();

$bootstrapUsername = 'e2e_bootstrap';

if ($action === 'cleanup') {
	try {
		$pdo->exec("DELETE FROM Users WHERE Username REGEXP '^e2e_'");
	} catch (Throwable $e) {
		http_response_code(500);
		echo json_encode(['error' => 'cleanup_failed']);
		exit;
	}
	echo json_encode(['ok' => true, 'action' => 'cleanup']);
	exit;
}

if ($action !== 'session') {
	http_response_code(400);
	echo json_encode(['error' => 'bad_action']);
	exit;
}

try {
	$stmt = $pdo->prepare('SELECT ID FROM Users WHERE Username = :u LIMIT 1');
	$stmt->execute([':u' => $bootstrapUsername]);
	$userId = (int) $stmt->fetchColumn();

	if ($userId <= 0) {
		$hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
		$ins = $pdo->prepare('INSERT INTO Users (Username, PasswordHash) VALUES (:u, :p)');
		$ins->execute([':u' => $bootstrapUsername, ':p' => $hash]);
		$userId = (int) $pdo->lastInsertId();

		$adminRoleId = (int) $pdo->query("SELECT ID FROM Roles WHERE Name = 'admin' LIMIT 1")->fetchColumn();
		if ($adminRoleId <= 0) {
			throw new RuntimeException('admin_role_missing');
		}

		$pdo->prepare('INSERT IGNORE INTO UserRoles (UserID, RoleID) VALUES (:uid, :rid)')
			->execute([':uid' => $userId, ':rid' => $adminRoleId]);
	}

	auth_establish_session($userId, 'admin', $bootstrapUsername, 'e2e');
	auth_log_signin_attempt($userId, $bootstrapUsername, 'local', 'allowed', 'e2e_bootstrap_session', 'e2e_bootstrap');
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => 'bootstrap_failed']);
	exit;
}

echo json_encode(['ok' => true, 'action' => 'session']);
