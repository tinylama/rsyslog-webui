<?php
declare(strict_types=1);

require_once __DIR__ . '/azure-oidc.php';

/**
 * Best-effort error reporting for Graph mail failures.
 * We intentionally keep this generic enough to show in UI without exposing secrets.
 */
function graph_mail_last_error(): ?string
{
	return isset($GLOBALS['graph_mail_last_error']) && is_string($GLOBALS['graph_mail_last_error']) ? $GLOBALS['graph_mail_last_error'] : null;
}

function graph_mail_set_last_error(string $msg): void
{
	$GLOBALS['graph_mail_last_error'] = $msg;
}

function graph_mail_is_configured(): bool
{
	global $graph_mail_sender, $graph_mail_enabled;
	$from = isset($graph_mail_sender) ? trim((string) $graph_mail_sender) : '';
	if ($from === '') {
		return false;
	}
	// If graph_mail_enabled is explicitly set in the DB, respect it.
	// When the key is missing (older DB), fall back to legacy behavior.
	$flag = $GLOBALS['graph_mail_enabled'] ?? null;
	if ($flag !== null) {
		$enabled = ($flag === '1') || (strtolower((string) $flag) === 'true');
		if (!$enabled) {
			return false;
		}
	}
	$s = auth_azure_settings();

	return $s['tenant_id'] !== '' && $s['client_id'] !== '' && $s['client_secret'] !== '';
}

function graph_mail_access_token(bool $forceRefresh = false): ?string
{
	static $cachedKey = '';
	static $cachedTok = null;
	static $cachedExp = 0;

	$s = auth_azure_settings();
	$tenant = $s['tenant_id'];
	$clientId = $s['client_id'];
	$clientSecret = $s['client_secret'];

	// Tokens are bound to tenant/client/secret. If settings change (e.g. you
	// update Entra client secret in the admin UI), we must not reuse an older
	// token. Cache the token only for the exact settings tuple.
	$key = hash('sha256', $tenant . '|' . $clientId . '|' . $clientSecret);
	if (!$forceRefresh && $cachedKey !== '' && $cachedKey === $key && $cachedTok !== null) {
		// Even when we reuse the cached token, keep the diagnostic snapshot
		// in sync so error messages can surface actionable hints.
		try {
			$GLOBALS['graph_mail_last_token_payload'] = null;
			$cachedExp = 0;
			if (is_string($cachedTok) && $cachedTok !== '') {
				$parts = explode('.', $cachedTok);
				if (count($parts) >= 2) {
					$payloadB64 = $parts[1];
					$payloadB64 = strtr($payloadB64, '-_', '+/');
					$padLen = strlen($payloadB64) % 4;
					if ($padLen) {
						$payloadB64 .= str_repeat('=', 4 - $padLen);
					}
					$payloadRaw = base64_decode($payloadB64, true);
					$payloadJson = $payloadRaw !== false ? json_decode($payloadRaw, true) : null;
					if (is_array($payloadJson)) {
						$GLOBALS['graph_mail_last_token_payload'] = $payloadJson;
						$e = $payloadJson['exp'] ?? 0;
						$cachedExp = is_numeric($e) ? (int) $e : 0;
					}
				}
			}
		} catch (Throwable $e) {
			// ignore diagnostics errors
		}

		// If token is expired (or will expire very soon), fetch a fresh one.
		if (!is_numeric($cachedExp) || $cachedExp <= (time() + 30)) {
			// fall through to refresh
		} else {
			return $cachedTok !== '' ? $cachedTok : null;
		}
	}

	$body = [
		'client_id' => $clientId,
		'client_secret' => $clientSecret,
		'scope' => 'https://graph.microsoft.com/.default',
		'grant_type' => 'client_credentials',
	];
	try {
		$json = auth_azure_http_post_form(
			'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
			$body
		);
	} catch (Throwable $e) {
		$cachedKey = $key;
		$cachedTok = '';
		graph_mail_set_last_error('Graph token request failed: ' . (string) $e->getMessage());
		return null;
	}
	$tok = isset($json['access_token']) && is_string($json['access_token']) ? $json['access_token'] : '';
	$cachedKey = $key;
	$cachedTok = $tok;
	$cachedExp = 0;

	// Diagnostic snapshot: token payload (no secrets).
	// Used to surface actionable hints when Graph denies operations.
	try {
		$GLOBALS['graph_mail_last_token_payload'] = null;
		$parts = explode('.', $tok);
		if (count($parts) >= 2) {
			$payloadB64 = $parts[1];
			$payloadB64 = strtr($payloadB64, '-_', '+/');
			$padLen = strlen($payloadB64) % 4;
			if ($padLen) {
				$payloadB64 .= str_repeat('=', 4 - $padLen);
			}
			$payloadRaw = base64_decode($payloadB64, true);
			$payloadJson = $payloadRaw !== false ? json_decode($payloadRaw, true) : null;
			if (is_array($payloadJson)) {
				$GLOBALS['graph_mail_last_token_payload'] = $payloadJson;
				$e = $payloadJson['exp'] ?? 0;
				$cachedExp = is_numeric($e) ? (int) $e : 0;
			}
		}
	} catch (Throwable $e) {
		// ignore diagnostics
	}

	return $tok !== '' ? $tok : null;
}

function graph_mail_send_json(string $method, string $url, array $payload): bool
{
	$json = json_encode($payload, JSON_THROW_ON_ERROR);

	for ($attempt = 0; $attempt < 2; $attempt++) {
		$tok = graph_mail_access_token($attempt === 1);
		if ($tok === null) {
			graph_mail_set_last_error(graph_mail_last_error() ?? 'Graph access token unavailable.');
			return false;
		}

		$ctx = stream_context_create([
			'http' => [
				'method' => $method,
				'header' => "Authorization: Bearer {$tok}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n",
				'content' => $json,
				'timeout' => 25,
				'ignore_errors' => true,
			],
		]);

		$raw = @file_get_contents($url, false, $ctx);
		if ($raw === false) {
			graph_mail_set_last_error('Graph request failed to fetch response.');
			return false;
		}

		$code = 0;
		if (isset($http_response_header) && is_array($http_response_header)) {
			foreach ($http_response_header as $line) {
				if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
					$code = (int) $m[1];
					break;
				}
			}
		}

		$ok = $code >= 200 && $code < 300;
		if ($ok) {
			return true;
		}

		$errMsg = null;
		$errCode = null;
		$hint = '';

		$decoded = json_decode((string) $raw, true);
		if (is_array($decoded)) {
			// Graph typically returns: { "error": { "code": "...", "message": "..."} }
			$err = $decoded['error'] ?? null;
			if (is_array($err)) {
				$errMsg = isset($err['message']) && is_string($err['message']) ? $err['message'] : null;
				$errCode = isset($err['code']) && is_string($err['code']) ? $err['code'] : null;
				$inner = $err['innerError'] ?? null;
				if (is_string($inner)) {
					// unlikely, keep backward compat
				} elseif (is_array($inner) && isset($inner['message']) && is_string($inner['message'])) {
					// innerError often carries the more actionable detail.
					$innerMsg = mb_substr(trim($inner['message']), 0, 160);
					if ($innerMsg !== '') {
						$errMsg = $innerMsg;
					}
				}
			}
		}
		if ($errMsg === null || $errMsg === '') {
			$errMsg = mb_substr(trim((string) $raw), 0, 300);
		}

		$roles = null;
		$hasRoles = false;
		$hasScp = false;

		// Determine whether the current token includes app roles (client_credentials case).
		$tokenPayload = $GLOBALS['graph_mail_last_token_payload'] ?? null;
		if (!is_array($tokenPayload) && is_string($tok) && $tok !== '') {
			try {
				$parts = explode('.', $tok);
				if (count($parts) >= 2) {
					$payloadB64 = $parts[1];
					$payloadB64 = strtr($payloadB64, '-_', '+/');
					$padLen = strlen($payloadB64) % 4;
					if ($padLen) {
						$payloadB64 .= str_repeat('=', 4 - $padLen);
					}
					$payloadRaw = base64_decode($payloadB64, true);
					$payloadJson = $payloadRaw !== false ? json_decode($payloadRaw, true) : null;
					if (is_array($payloadJson)) {
						$tokenPayload = $payloadJson;
					}
				}
			} catch (Throwable $e) {
				// ignore
			}
		}

		if (is_array($tokenPayload)) {
			$roles = $tokenPayload['roles'] ?? null;
			$hasRoles = is_array($roles) ? count($roles) > 0 : false;
			$hasScp = isset($tokenPayload['scp']) && is_string($tokenPayload['scp']) && trim($tokenPayload['scp']) !== '';
		}

		$codeLower = is_string($errCode) ? strtolower((string) $errCode) : '';
		$denied = in_array($codeLower, ['erroraccessdenied', 'authorization_requestdenied'], true);

		// If Graph denies because the token has no app roles, re-fetch a fresh token once.
		// This covers the common case where admin consent was just granted.
		if ($attempt === 0 && $denied && !$hasRoles && !$hasScp) {
			graph_mail_set_last_error(
				'Graph mail sending denied (HTTP ' . (string) $code . '): missing app roles; retrying after refreshing token...'
			);
			continue;
		}

		if ($denied && !$hasRoles && !$hasScp) {
			$hint = ' Hint: Graph token contains no app roles. Confirm the app has `Mail.Send` as an APPLICATION permission with admin consent, and that Exchange app-only mailbox access is allowed for the configured sender mailbox.';
		}

		$prefix = 'Graph mail sending failed (HTTP ' . (string) $code . '): ';
		$extra = $errCode !== null && $errCode !== '' ? ('[' . $errCode . '] ') : '';
		graph_mail_set_last_error($prefix . $extra . (string) $errMsg . $hint);
		return false;
	}

	return false;
}

function graph_mail_escape_html(string $v): string
{
	return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @return array{html:string,text:string}
 */
function graph_mail_render_template(
	string $brand,
	string $title,
	string $intro,
	string $bodyHtml,
	?string $code = null,
	?string $footerNote = null
): array {
	$brandSafe = graph_mail_escape_html($brand);
	$titleSafe = graph_mail_escape_html($title);
	$introSafe = graph_mail_escape_html($intro);
	$footerSafe = $footerNote !== null ? graph_mail_escape_html($footerNote) : '';
	$codeSafe = $code !== null ? graph_mail_escape_html($code) : null;

	$codeBlock = '';
	if ($codeSafe !== null && $codeSafe !== '') {
		$codeBlock = '<div style="margin:18px 0;padding:14px 16px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:10px;text-align:center;">'
			. '<div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6c757d;margin-bottom:6px;">Verification code</div>'
			. '<div style="font-size:30px;line-height:1.1;font-weight:700;letter-spacing:.22em;color:#0d6efd;font-family:Consolas,Monaco,ui-monospace,SFMono-Regular,Menlo,monospace;">'
			. $codeSafe
			. '</div></div>';
	}

	$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
		. '<body style="margin:0;padding:0;background:#f3f5f7;color:#1f2937;font-family:Segoe UI,Roboto,Arial,sans-serif;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f7;padding:20px 10px;"><tr><td align="center">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #dfe4ea;border-radius:14px;overflow:hidden;">'
		. '<tr><td style="padding:18px 22px;background:linear-gradient(135deg,#0d6efd,#084298);color:#ffffff;">'
		. '<div style="font-size:18px;font-weight:700;">' . $brandSafe . '</div>'
		. '<div style="margin-top:4px;font-size:13px;opacity:.92;">Security notification</div>'
		. '</td></tr>'
		. '<tr><td style="padding:24px 22px;">'
		. '<h1 style="margin:0 0 10px 0;font-size:22px;line-height:1.25;color:#111827;">' . $titleSafe . '</h1>'
		. '<p style="margin:0 0 14px 0;color:#4b5563;font-size:15px;line-height:1.55;">' . $introSafe . '</p>'
		. $codeBlock
		. $bodyHtml;
	if ($footerSafe !== '') {
		$html .= '<p style="margin:16px 0 0 0;color:#6b7280;font-size:13px;line-height:1.5;">' . $footerSafe . '</p>';
	}
	$html .= '</td></tr>'
		. '<tr><td style="padding:14px 22px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:12px;">'
		. 'This is an automated message from ' . $brandSafe . '.'
		. '</td></tr></table></td></tr></table></body></html>';

	$text = $title . "\n\n"
		. $intro . "\n\n"
		. ($code !== null ? ("Verification code: " . $code . "\n\n") : '')
		. trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml))) . "\n\n"
		. ($footerNote !== null ? ($footerNote . "\n\n") : '')
		. "This is an automated message from " . $brand . ".";

	return ['html' => $html, 'text' => $text];
}

function graph_mail_send_mfa_code(string $toEmail, string $sixDigitCode): bool
{
	global $graph_mail_sender, $site_name;
	$from = isset($graph_mail_sender) ? trim((string) $graph_mail_sender) : '';
	if ($from === '' || !graph_mail_is_configured()) {
		graph_mail_set_last_error('Graph mail is not configured.');
		return false;
	}
	$brand = isset($site_name) ? (string) $site_name : 'System Logs';
	// Generic code email used for account verification flows (password reset / email change).
	$subject = $brand . ' — verification code';
	$mail = graph_mail_render_template(
		$brand,
		'Your verification code',
		'Use the code below to continue your account verification flow.',
		'<p style="margin:0;color:#4b5563;font-size:14px;line-height:1.55;">If you did not request this, you can safely ignore this email.</p>',
		$sixDigitCode,
		'For security, this code expires shortly.'
	);
	$payload = [
		'message' => [
			// Explicitly set sender. While the /users/{id}/sendMail endpoint implies the sender,
			// some app access configurations are more reliable when "from" is present.
			'from' => [
				'emailAddress' => [
					'address' => $from,
				],
			],
			'subject' => $subject,
			'body' => [
				'contentType' => 'HTML',
				'content' => $mail['html'],
			],
			'toRecipients' => [
				['emailAddress' => ['address' => $toEmail]],
			],
		],
		'saveToSentItems' => false,
	];
	$url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($from) . '/sendMail';

	try {
		return graph_mail_send_json('POST', $url, $payload);
	} catch (Throwable $e) {
		graph_mail_set_last_error('Graph mail sending exception: ' . (string) $e->getMessage());
		return false;
	}
}

/**
 * Small functional test to verify the app can actually send with application permissions.
 * This is used when enabling Graph mail so we don't claim activation when sendMail is 403.
 *
 * Note: this will send a single test email to the configured sender mailbox.
 */
function graph_mail_send_test(): bool
{
	global $graph_mail_sender, $site_name;
	$from = isset($graph_mail_sender) ? trim((string) $graph_mail_sender) : '';
	if ($from === '') {
		graph_mail_set_last_error('Graph mail sender mailbox is empty.');
		return false;
	}

	$brand = isset($site_name) ? (string) $site_name : 'System Logs';
	$subject = $brand . ' — email delivery test';
	$mail = graph_mail_render_template(
		$brand,
		'Email delivery test',
		'This is a one-time test email to verify the Microsoft Graph delivery integration.',
		'<p style="margin:0;color:#4b5563;font-size:14px;line-height:1.55;">If you can read this message, outbound email is correctly configured for this mailbox.</p>'
	);
	$payload = [
		'message' => [
			'from' => [
				'emailAddress' => [
					'address' => $from,
				],
			],
			'subject' => $subject,
			'body' => [
				'contentType' => 'HTML',
				'content' => $mail['html'],
			],
			'toRecipients' => [
				['emailAddress' => ['address' => $from]],
			],
		],
		'saveToSentItems' => false,
	];
	$url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($from) . '/sendMail';

	try {
		$ok = graph_mail_send_json('POST', $url, $payload);

		// If the functional test fails, probe mailbox access to make the
		// failure reason more actionable for admin setup.
		if (!$ok) {
			$tok = graph_mail_access_token();
			$probeDetail = '';
			if ($tok === null) {
				$probeDetail = 'Graph user probe: token null';
			} else {
				$probeUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($from);
				$probeCtx = stream_context_create([
					'http' => [
						'method' => 'GET',
						'header' => "Authorization: Bearer {$tok}\r\n",
						'timeout' => 20,
						'ignore_errors' => true,
					],
				]);
				$probeRaw = @file_get_contents($probeUrl, false, $probeCtx);
				if (is_string($probeRaw) && $probeRaw !== '') {
					// Keep it small: enough for code/message/error.code.
					$probeJson = json_decode($probeRaw, true);
					$probeDetail = $probeJson !== null
						? json_encode($probeJson, JSON_UNESCAPED_SLASHES)
						: mb_substr(trim($probeRaw), 0, 400);
					$probeDetail = mb_substr((string) $probeDetail, 0, 600);
				} else {
					$probeDetail = 'Graph user probe: empty/no response';
				}
			}

			$prev = graph_mail_last_error();
			graph_mail_set_last_error((string) ($prev ?? '') . ' | ' . (string) $probeDetail);
		}

		return $ok;
	} catch (Throwable $e) {
		graph_mail_set_last_error('Graph mail delivery test exception: ' . (string) $e->getMessage());
		return false;
	}
}
