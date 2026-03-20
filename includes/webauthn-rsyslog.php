<?php
declare(strict_types=1);

require_once __DIR__ . '/crypto-settings.php';
require_once __DIR__ . '/mfa.php';

// WebAuthn library classes are loaded via Composer. This file is included
// directly by HTML and JSON endpoints, so ensure the Composer autoloader
// is available regardless of which entry point was hit first.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
	require_once $autoload;
}

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

function auth_webauthn_rp_host(): string
{
	// Behind reverse proxies (Traefik), HTTP_HOST may not be the public host.
	// Prefer X-Forwarded-Host when available.
	$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
	if (is_string($host) && str_contains($host, ',')) {
		$host = trim(explode(',', $host, 2)[0]);
	}
	if (str_contains($host, ':')) {
		$host = explode(':', $host, 2)[0];
	}

	return strtolower($host);
}

function auth_webauthn_user_handle_bin(int $userId): string
{
	return hash_hmac('sha256', 'webauthn-user:' . $userId, auth_crypto_key_binary(), true);
}

function auth_webauthn_serializer(): SerializerInterface
{
	static $s = null;
	if ($s === null) {
		$m = new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
		$s = (new WebauthnSerializerFactory($m))->create();
	}

	return $s;
}

function auth_webauthn_decode_credential(string $json): ?PublicKeyCredential
{
	try {
		return auth_webauthn_serializer()->deserialize($json, PublicKeyCredential::class, 'json');
	} catch (Throwable $e) {
		// Keep a sanitized reason for UI debugging.
		$GLOBALS['webauthn_last_error_detail'] = is_string($e->getMessage()) ? (string) $e->getMessage() : 'decode_failed';

		// Some clients/libraries treat binary fields as base64url while others
		// require base64. If deserialization fails, try again after converting
		// base64url -> base64 for the known credential fields.
		try {
			$arr = json_decode($json, true);
			if (!is_array($arr)) {
				return null;
			}

			// Some clients/library combinations may represent binary credential
			// fields as base64url strings, standard base64 strings, *or* as JSON
			// arrays of bytes. The WebAuthn serializer expects base64 (often
			// without padding), so we normalize these representations.
			$convert = function ($s) {
				if ($s === null) return null;

				// Pass through strings (normalize base64url -> base64 when needed).
				if (is_string($s)) {
					if ($s === '') return null;
					// Convert only when it looks base64url-ish.
					if (str_contains($s, '-') || str_contains($s, '_')) {
						$s = str_replace('-', '+', $s);
						$s = str_replace('_', '/', $s);
						$pad = strlen($s) % 4;
						if ($pad) $s .= str_repeat('=', 4 - $pad);
					}
					// Serializer sometimes expects no-padding base64.
					return rtrim($s, '=');
				}

				// If we received JSON arrays (e.g. [1,2,3]), treat them as bytes.
				if (is_array($s)) {
					$isByteList = true;
					$bytes = [];
					foreach ($s as $v) {
						if (!is_numeric($v)) {
							$isByteList = false;
							break;
						}
						$iv = (int) $v;
						if ($iv < 0 || $iv > 255) {
							$isByteList = false;
							break;
						}
						$bytes[] = $iv;
					}
					if ($isByteList && $bytes !== []) {
						$bin = pack('C*', ...$bytes);
						$b64 = base64_encode($bin);
						return rtrim($b64, '=');
					}
					return null;
				}

				// Unknown types => fail gracefully (avoid fatal decode errors).
				return null;
			};

			$normalizeBinaryField = function (array &$node, string $key) use ($convert): void {
				if (!array_key_exists($key, $node)) {
					return;
				}
				$normalized = $convert($node[$key]);
				// Never write null into binary fields; remove invalid values so the
				// serializer does not fatally try to decode null.
				if (is_string($normalized) && $normalized !== '') {
					$node[$key] = $normalized;
					return;
				}
				unset($node[$key]);
			};

			$normalizeBinaryField($arr, 'id');
			$normalizeBinaryField($arr, 'rawId');
			// Some providers send only one of id/rawId. The webauthn-lib
			// denormalizer expects both and validates they match.
			if (!isset($arr['id']) && isset($arr['rawId']) && is_string($arr['rawId'])) {
				$arr['id'] = $arr['rawId'];
			}
			if (!isset($arr['rawId']) && isset($arr['id']) && is_string($arr['id'])) {
				$arr['rawId'] = $arr['id'];
			}
			if (isset($arr['response']) && is_array($arr['response'])) {
				$normalizeBinaryField($arr['response'], 'clientDataJSON');
				$normalizeBinaryField($arr['response'], 'attestationObject');
			}

			$json2 = json_encode($arr, JSON_UNESCAPED_SLASHES);
			if (!is_string($json2) || $json2 === '') {
				return null;
			}

			// Second attempt.
			return auth_webauthn_serializer()->deserialize($json2, PublicKeyCredential::class, 'json');
		} catch (Throwable $e2) {
			$GLOBALS['webauthn_last_error_detail'] = is_string($e2->getMessage()) ? (string) $e2->getMessage() : 'decode_failed_2';
			return null;
		}
	}
}

function auth_webauthn_attestation_validator(): AuthenticatorAttestationResponseValidator
{
	$rp = auth_webauthn_rp_host();
	$factory = new CeremonyStepManagerFactory();
	$factory->setSecuredRelyingPartyId([$rp]);
	$ceremony = $factory->creationCeremony([$rp]);

	return new AuthenticatorAttestationResponseValidator(null, null, null, null, null, $ceremony);
}

function auth_webauthn_assertion_validator(): AuthenticatorAssertionResponseValidator
{
	$rp = auth_webauthn_rp_host();
	$factory = new CeremonyStepManagerFactory();
	$factory->setSecuredRelyingPartyId([$rp]);
	$ceremony = $factory->requestCeremony([$rp]);

	return AuthenticatorAssertionResponseValidator::create(null, null, null, null, null, $ceremony);
}

/**
 * @return array{options: PublicKeyCredentialCreationOptions, json: string}|null
 */
function auth_webauthn_begin_registration(PDO $pdo, int $userId, string $username, string $displayName): ?array
{
	try {
		auth_ensure_mfa_schema($pdo);
		global $site_name;
		$rpHost = auth_webauthn_rp_host();
		// Store RP ID host used for the creation ceremony.
		// This avoids mismatches when a reverse proxy forwards different host headers
		// between the "options" and the "verify" requests.
		$_SESSION['webauthn_register_rp_host'] = $rpHost;
		$label = isset($site_name) ? (string) $site_name : 'App';
		$rpEntity = PublicKeyCredentialRpEntity::create($label, $rpHost);
		$handle = auth_webauthn_user_handle_bin($userId);
		$userEntity = PublicKeyCredentialUserEntity::create($username, $handle, $displayName !== '' ? $displayName : $username);
		$challenge = random_bytes(32);
		$params = [
			PublicKeyCredentialParameters::createPk(-7),
			PublicKeyCredentialParameters::createPk(-257),
		];
		$sel = AuthenticatorSelectionCriteria::create(
			null,
			AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
			AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
		);
		$stmt = $pdo->prepare('SELECT CredentialId FROM UserWebAuthnCredentials WHERE UserID = :id');
		$stmt->execute([':id' => $userId]);
		$exclude = [];
		while ($cid = $stmt->fetchColumn()) {
			if (!is_string($cid) || $cid === '') {
				continue;
			}
			$exclude[] = PublicKeyCredentialDescriptor::create(
				PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
				$cid,
				[]
			);
		}
		$options = PublicKeyCredentialCreationOptions::create(
			$rpEntity,
			$userEntity,
			$challenge,
			$params,
			$sel,
			PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
			$exclude,
			60000
		);
		$ser = auth_webauthn_serializer()->serialize($options, 'json');

		return ['options' => $options, 'json' => $ser];
	} catch (Throwable $e) {
		return null;
	}
}

function auth_webauthn_finish_registration(PDO $pdo, int $userId, string $credentialJson, ?string $friendlyName): bool
{
	try {
		auth_ensure_mfa_schema($pdo);
		$sess = $_SESSION['webauthn_register_json'] ?? '';
		if (!is_string($sess) || $sess === '') {
			$GLOBALS['webauthn_last_error'] = 'missing_session_options';
			return false;
		}
		/** @var PublicKeyCredentialCreationOptions $saved */
		$saved = null;
		try {
			$saved = auth_webauthn_serializer()->deserialize($sess, PublicKeyCredentialCreationOptions::class, 'json');
		} catch (Throwable $e) {
			$GLOBALS['webauthn_last_error'] = 'options_deserialize_failed';
			return false;
		}
		$pk = auth_webauthn_decode_credential($credentialJson);
		if ($pk === null || !($pk->response instanceof AuthenticatorAttestationResponse)) {
			$GLOBALS['webauthn_last_error'] = 'credential_decode_failed';
			return false;
		}
		try {
			$rpHost = $_SESSION['webauthn_register_rp_host'] ?? auth_webauthn_rp_host();
			$source = auth_webauthn_attestation_validator()->check(
				$pk->response,
				$saved,
				$rpHost
			);
		} catch (Throwable $e) {
			$GLOBALS['webauthn_last_error'] = 'attestation_validation_failed';
			return false;
		}
		$json = auth_webauthn_serializer()->serialize($source, 'json');
		$cid = $source->publicKeyCredentialId;
		$name = $friendlyName !== null && trim($friendlyName) !== '' ? trim($friendlyName) : 'Passkey';
		$ins = $pdo->prepare(
			'INSERT INTO UserWebAuthnCredentials (UserID, CredentialId, PublicKey, SignCount, FriendlyName)
			 VALUES (:uid, :cid, :pk, :sc, :fn)'
		);
		$ins->execute([
			':uid' => $userId,
			':cid' => $cid,
			':pk' => $json,
			':sc' => $source->counter,
			':fn' => $name,
		]);
		unset($_SESSION['webauthn_register_json']);

		return true;
	} catch (Throwable $e) {
		$GLOBALS['webauthn_last_error'] = 'finish_exception';
		return false;
	}
}

/**
 * @return array{options: PublicKeyCredentialRequestOptions, json: string}|null
 */
function auth_webauthn_begin_assertion(PDO $pdo, int $userId): ?array
{
	auth_ensure_mfa_schema($pdo);
	$stmt = $pdo->prepare(
		'SELECT CredentialId FROM UserWebAuthnCredentials WHERE UserID = :id'
	);
	$stmt->execute([':id' => $userId]);
	$allow = [];
	while ($cid = $stmt->fetchColumn()) {
		if (!is_string($cid) || $cid === '') {
			continue;
		}
		$allow[] = PublicKeyCredentialDescriptor::create(
			PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
			$cid,
			[]
		);
	}
	if ($allow === []) {
		return null;
	}
	$challenge = random_bytes(32);
	$options = PublicKeyCredentialRequestOptions::create(
		$challenge,
		auth_webauthn_rp_host(),
		$allow,
		PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
		60000
	);
	$json = auth_webauthn_serializer()->serialize($options, 'json');

	return ['options' => $options, 'json' => $json];
}

function auth_webauthn_load_source(PDO $pdo, string $rawCredentialId): ?PublicKeyCredentialSource
{
	auth_ensure_mfa_schema($pdo);
	$stmt = $pdo->prepare(
		'SELECT ID, PublicKey, SignCount FROM UserWebAuthnCredentials WHERE CredentialId = :c LIMIT 1'
	);
	$stmt->execute([':c' => $rawCredentialId]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	if ($row === false) {
		return null;
	}
	try {
		return auth_webauthn_serializer()->deserialize(
			(string) $row['PublicKey'],
			PublicKeyCredentialSource::class,
			'json'
		);
	} catch (Throwable $e) {
		return null;
	}
}

function auth_webauthn_finish_assertion(PDO $pdo, int $userId, string $credentialJson): bool
{
	auth_ensure_mfa_schema($pdo);
	$sess = $_SESSION['webauthn_assert_json'] ?? '';
	if (!is_string($sess) || $sess === '') {
		return false;
	}
	try {
		/** @var PublicKeyCredentialRequestOptions $requestOpts */
		$requestOpts = auth_webauthn_serializer()->deserialize($sess, PublicKeyCredentialRequestOptions::class, 'json');
	} catch (Throwable $e) {
		return false;
	}
	$pk = auth_webauthn_decode_credential($credentialJson);
	if ($pk === null || !($pk->response instanceof AuthenticatorAssertionResponse)) {
		return false;
	}
	$source = auth_webauthn_load_source($pdo, $pk->rawId);
	if ($source === null) {
		return false;
	}
	$stmt = $pdo->prepare('SELECT ID FROM UserWebAuthnCredentials WHERE CredentialId = :c AND UserID = :u LIMIT 1');
	$stmt->execute([':c' => $pk->rawId, ':u' => $userId]);
	$rowId = $stmt->fetchColumn();
	if ($rowId === false) {
		return false;
	}
	$handle = auth_webauthn_user_handle_bin($userId);
	try {
		$updated = auth_webauthn_assertion_validator()->check(
			$source,
			$pk->response,
			$requestOpts,
			auth_webauthn_rp_host(),
			$handle
		);
	} catch (Throwable $e) {
		return false;
	}
	$ser = auth_webauthn_serializer()->serialize($updated, 'json');
	$upd = $pdo->prepare(
		'UPDATE UserWebAuthnCredentials SET PublicKey = :pk, SignCount = :sc WHERE ID = :id'
	);
	$upd->execute([
		':pk' => $ser,
		':sc' => $updated->counter,
		':id' => (int) $rowId,
	]);
	unset($_SESSION['webauthn_assert_json']);

	return true;
}

function auth_webauthn_delete_credential(PDO $pdo, int $userId, int $credDbId): bool
{
	auth_ensure_mfa_schema($pdo);
	$stmt = $pdo->prepare('DELETE FROM UserWebAuthnCredentials WHERE ID = :id AND UserID = :u');
	$stmt->execute([':id' => $credDbId, ':u' => $userId]);

	return $stmt->rowCount() > 0;
}
