<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/webauthn-rsyslog.php';

header('Content-Type: application/json; charset=utf-8');

if (!auth_is_enabled()) {
	http_response_code(401);
	echo json_encode(['ok' => false, 'error' => 'unauthorized']);
	exit;
}
auth_bootstrap_once();

$p = auth_mfa_pending();
if ($p === null || !empty($_SESSION['user_id'])) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'forbidden']);
	exit;
}

$raw = file_get_contents('php://input');
$raw = is_string($raw) ? $raw : '';
if ($raw === '') {
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => 'empty']);
	exit;
}

$pdo = auth_db();
$uid = $p['user_id'];
if (auth_webauthn_finish_assertion($pdo, $uid, $raw)) {
	auth_mfa_clear_pending();
	$dn = $p['display_name'] ?? $p['username'] ?? 'user';
	auth_establish_session($uid, $p['role'], $dn, $p['provider']);
	auth_log_signin_attempt($uid, (string) ($p['username'] ?? $dn), (string) ($p['provider'] ?? 'local'), 'allowed', 'mfa_passkey_verified', 'webauthn_assert');
	echo json_encode(['ok' => true, 'redirect' => $p['next']]);
	exit;
}

auth_mfa_bump_attempts();
auth_log_signin_attempt($uid, (string) ($p['username'] ?? ''), (string) ($p['provider'] ?? 'local'), 'failed', 'mfa_passkey_failed', 'webauthn_assert');
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'verify_failed']);
