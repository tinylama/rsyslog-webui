<?php
/**
 * App secret derivation and AES-256-GCM for settings / TOTP at rest.
 */
declare(strict_types=1);

function auth_app_secret_string(): string
{
	global $session_secret;
	$env = getenv('RSYSLOG_APP_SECRET');
	if (is_string($env) && $env !== '') {
		return $env;
	}
	$fallback = isset($session_secret) && is_string($session_secret) ? $session_secret : 'dev';

	return $fallback;
}

function auth_crypto_key_binary(): string
{
	$material = auth_app_secret_string();

	return hash('sha256', 'rsyslog-crypto-v1|' . $material, true);
}

function auth_encrypt_secret_at_rest(string $plaintext): string
{
	if ($plaintext === '') {
		return '';
	}
	$key = auth_crypto_key_binary();
	$iv = random_bytes(12);
	$tag = '';
	$ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
	if ($ct === false || strlen($tag) !== 16) {
		return '';
	}

	return 'enc:v1:' . base64_encode($iv . $tag . $ct);
}

function auth_decrypt_secret_at_rest(string $stored): ?string
{
	if ($stored === '' || !str_starts_with($stored, 'enc:v1:')) {
		return null;
	}
	// 'enc:v1:' is 7 chars; stripping one extra char corrupts the base64 payload.
	$raw = base64_decode(substr($stored, 7), true);
	if ($raw === false || strlen($raw) < 12 + 16 + 1) {
		return null;
	}
	$iv = substr($raw, 0, 12);
	$tag = substr($raw, 12, 16);
	$ct = substr($raw, 28);
	$key = auth_crypto_key_binary();
	$plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
	if ($plain === false) {
		return null;
	}

	return $plain;
}
