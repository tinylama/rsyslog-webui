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
	echo json_encode(['ok' => false]);
	exit;
}

$baseline = auth_security_baseline_status();
if (!($baseline['safe'] ?? false)) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'insecure_baseline', 'detail' => (string) ($baseline['detail'] ?? '')], JSON_UNESCAPED_SLASHES);
	exit;
}

$raw = file_get_contents('php://input');
$raw = is_string($raw) ? $raw : '';
if ($raw === '') {
	http_response_code(400);
	echo json_encode(['ok' => false]);
	exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
	http_response_code(400);
	echo json_encode(['ok' => false]);
	exit;
}
// Some browsers include optional WebAuthn fields as null values in toJSON().
// Strip null recursively so the server-side deserializer only sees explicit data.
$stripNulls = function ($value) use (&$stripNulls) {
	if ($value === null) {
		return null;
	}
	if (is_array($value)) {
		$out = [];
		foreach ($value as $k => $v) {
			$sv = $stripNulls($v);
			if ($sv !== null) {
				$out[$k] = $sv;
			}
		}
		return $out;
	}
	return $value;
};
$data = $stripNulls($data);
if (!is_array($data)) {
	http_response_code(400);
	echo json_encode(['ok' => false]);
	exit;
}
$csrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
if (!isset($_SESSION['csrf_webauthn']) || !hash_equals($_SESSION['csrf_webauthn'], $csrf)) {
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'csrf']);
	exit;
}
$credRaw = $data['credential'] ?? null;
if (is_string($credRaw) && $credRaw !== '') {
	try {
		$decoded = json_decode($credRaw, true, 512, JSON_THROW_ON_ERROR);
		if (is_array($decoded)) {
			$credRaw = $decoded;
		}
	} catch (Throwable $e) {}
}
if (is_array($credRaw)) {
	$normalizeBinary = function ($value): ?string {
		if ($value === null) {
			return null;
		}
		if (is_string($value)) {
			$s = trim($value);
			if ($s === '') {
				return null;
			}
			if (str_contains($s, '-') || str_contains($s, '_')) {
				$s = str_replace('-', '+', $s);
				$s = str_replace('_', '/', $s);
			}
			$s = rtrim($s, '=');
			return $s !== '' ? $s : null;
		}
		// Some wrappers can send byte arrays (e.g., [12, 34, ...]) instead of base64url strings.
		if (is_array($value)) {
			$bytes = [];
			foreach ($value as $v) {
				if (!is_numeric($v)) {
					return null;
				}
				$iv = (int) $v;
				if ($iv < 0 || $iv > 255) {
					return null;
				}
				$bytes[] = $iv;
			}
			if ($bytes === []) {
				return null;
			}
			return rtrim(base64_encode(pack('C*', ...$bytes)), '=');
		}
		return null;
	};

	$idNorm = $normalizeBinary($credRaw['id'] ?? null);
	$rawIdNorm = $normalizeBinary($credRaw['rawId'] ?? null);
	if ($idNorm === null && $rawIdNorm !== null) {
		$idNorm = $rawIdNorm;
	}
	if ($rawIdNorm === null && $idNorm !== null) {
		$rawIdNorm = $idNorm;
	}
	if ($idNorm !== null) {
		$credRaw['id'] = $idNorm;
	}
	if ($rawIdNorm !== null) {
		$credRaw['rawId'] = $rawIdNorm;
	}

	$resp = $credRaw['response'] ?? null;
	$clientDataNorm = is_array($resp) ? $normalizeBinary($resp['clientDataJSON'] ?? null) : null;
	$attestationNorm = is_array($resp) ? $normalizeBinary($resp['attestationObject'] ?? null) : null;
	if (is_array($resp)) {
		if ($clientDataNorm !== null) {
			$credRaw['response']['clientDataJSON'] = $clientDataNorm;
		}
		if ($attestationNorm !== null) {
			$credRaw['response']['attestationObject'] = $attestationNorm;
		}
	}

	$isValid = is_string($idNorm) && $idNorm !== ''
		&& is_string($rawIdNorm) && $rawIdNorm !== ''
		&& is_string($clientDataNorm) && $clientDataNorm !== ''
		&& is_string($attestationNorm) && $attestationNorm !== '';
	if (!$isValid) {
		http_response_code(400);
		echo json_encode(['ok' => false, 'error' => 'verify_failed', 'detail' => 'malformed_credential'], JSON_UNESCAPED_SLASHES);
		exit;
	}
}
try {
	if (is_array($credRaw)) {
		$credJson = json_encode($credRaw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	} elseif (is_string($credRaw) && $credRaw !== '') {
		$credJson = $credRaw;
	} else {
		$credJson = '';
	}
} catch (Throwable $e) {
	$credJson = '';
}
if ($credJson === '') {
	http_response_code(400);
	echo json_encode(['ok' => false]);
	exit;
}
$name = isset($data['friendlyName']) && is_string($data['friendlyName']) ? $data['friendlyName'] : null;

$pdo = auth_db();
$uid = (int) $me['id'];
if (auth_webauthn_finish_registration($pdo, $uid, $credJson, $name)) {
	echo json_encode(['ok' => true]);
	exit;
}

// Provide a sanitized failure reason to speed up debugging.
$detail = '';
$detail2 = '';
try {
	$detail = is_string($GLOBALS['webauthn_last_error'] ?? null) ? (string) ($GLOBALS['webauthn_last_error'] ?? '') : '';
	$detail2 = is_string($GLOBALS['webauthn_last_error_detail'] ?? null) ? (string) ($GLOBALS['webauthn_last_error_detail'] ?? '') : '';
	$detail2 = $detail2 !== '' ? mb_substr(trim($detail2), 0, 200) : '';
} catch (Throwable $e) {}

http_response_code(400);
if ($detail2 !== '') {
	$detail = trim($detail . ' (' . $detail2 . ')');
}
echo json_encode(['ok' => false, 'error' => 'verify_failed', 'detail' => $detail], JSON_UNESCAPED_SLASHES);
