<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');

require_login(true);
auth_bootstrap_once();

$me = auth_current_user();
if ($me === null) {
	http_response_code(401);
	echo json_encode(['ok' => false, 'error' => 'unauthorized']);
	exit;
}

if (!isset($_SESSION['csrf_onboarding']) || !is_string($_SESSION['csrf_onboarding']) || strlen($_SESSION['csrf_onboarding']) < 32) {
	$_SESSION['csrf_onboarding'] = bin2hex(random_bytes(32));
}

$posted = $_POST['csrf_token'] ?? '';
if (!is_string($posted) || !hash_equals($_SESSION['csrf_onboarding'], $posted)) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'csrf']);
	exit;
}

$pdo = auth_db();
$uid = (int) $me['id'];

$stmt = $pdo->prepare('UPDATE Users SET UsersOnboardingShown = 1 WHERE ID = :id');
$stmt->execute([':id' => $uid]);

echo json_encode(['ok' => true]);

