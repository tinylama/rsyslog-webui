<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/webauthn-rsyslog.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_is_enabled()) {
	http_response_code(401);
	echo json_encode(['error' => 'unauthorized']);
	exit;
}
auth_bootstrap_once();

$p = auth_mfa_pending();
if ($p === null || !empty($_SESSION['user_id'])) {
	http_response_code(403);
	echo json_encode(['error' => 'forbidden']);
	exit;
}

$pdo = auth_db();
$beg = auth_webauthn_begin_assertion($pdo, $p['user_id']);
if ($beg === null) {
	http_response_code(404);
	echo json_encode(['error' => 'no_passkeys']);
	exit;
}
$_SESSION['webauthn_assert_json'] = $beg['json'];
$arr = json_decode($beg['json'], true);
if (!is_array($arr)) {
	http_response_code(500);
	echo json_encode(['error' => 'serialize']);
	exit;
}
echo json_encode(['publicKey' => $arr], JSON_UNESCAPED_SLASHES);
