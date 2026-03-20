<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/webauthn-rsyslog.php';

header('Content-Type: application/json; charset=utf-8');

require_login(true);
auth_bootstrap_once();

$me = auth_current_user();
if ($me === null) {
	http_response_code(401);
	echo json_encode(['error' => 'unauthorized']);
	exit;
}

$baseline = auth_security_baseline_status();
if (!($baseline['safe'] ?? false)) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'insecure_baseline', 'detail' => (string) ($baseline['detail'] ?? '')], JSON_UNESCAPED_SLASHES);
	exit;
}

if (!isset($_SESSION['csrf_webauthn']) || !is_string($_SESSION['csrf_webauthn']) || strlen($_SESSION['csrf_webauthn']) < 32) {
	$_SESSION['csrf_webauthn'] = bin2hex(random_bytes(32));
}
$q = $_GET['csrf'] ?? '';
if (!is_string($q) || !hash_equals($_SESSION['csrf_webauthn'], $q)) {
	http_response_code(403);
	echo json_encode(['error' => 'csrf']);
	exit;
}

$pdo = auth_db();
$uid = (int) $me['id'];
$stmt = $pdo->prepare('SELECT Username FROM Users WHERE ID = :id LIMIT 1');
$stmt->execute([':id' => $uid]);
$uname = (string) $stmt->fetchColumn();
$disp = (string) ($me['display_name'] ?? $uname);
$beg = auth_webauthn_begin_registration($pdo, $uid, $uname, $disp);
if ($beg === null) {
	http_response_code(500);
	echo json_encode(['error' => 'options']);
	exit;
}
$_SESSION['webauthn_register_json'] = $beg['json'];
$arr = json_decode($beg['json'], true);
if (!is_array($arr)) {
	http_response_code(500);
	echo json_encode(['error' => 'serialize']);
	exit;
}
echo json_encode(['publicKey' => $arr], JSON_UNESCAPED_SLASHES);
