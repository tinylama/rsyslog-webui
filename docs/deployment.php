<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
$siteTitle = htmlspecialchars((string) ($site_name ?? 'System Logs'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Deployment and TLS - <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
	<link href="../css/docs-site.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="docs-shell">
	<?php $docsActive = 'deployment'; include __DIR__ . '/../includes/partials/docs-nav.php'; ?>
	<div class="docs-content">
		<h1 class="h3">Deployment and TLS</h1>
		<p class="text-muted">Production uses Traefik. You can use <strong>automated</strong> Let’s Encrypt (Cloudflare DNS-01) or <strong>manual</strong> certificate files—no Cloudflare API key required for the second path.</p>
		<div class="alert alert-light border shadow-sm mb-4" role="note">
			<p class="small fw-semibold mb-2">TLS mode quick pick</p>
			<ul class="small mb-0">
				<li><strong>Automated (default):</strong> <code>docker-compose.yml</code> + <code>.env.tls</code> from <code>docker/tls.env.example</code> (includes <code>COMPOSE_PROFILES=tls</code>) — <code>docker compose --env-file .env.tls up -d --build</code>. See <a href="#tls-automated">Automated TLS (Cloudflare)</a>.</li>
				<li><strong>Automated (alternate):</strong> <code>docker-compose.tls.yml</code> — same <code>.env.tls</code> variables.</li>
				<li><strong>Manual:</strong> <code>docker-compose.tls-manual.yml</code> — place <code>docker/certs/tls.crt</code> and <code>docker/certs/tls.key</code> on the host. See <a href="#tls-manual">Manual TLS (your certificates)</a>.</li>
			</ul>
		</div>
		<h2 class="h5 mt-3">Deployment flow</h2>
		<pre><code class="language-mermaid">flowchart LR
Client[Client Browser] --> Edge[DNS to your host]
Edge --> Traefik[Traefik TLS 80/443]
Traefik --> WebUI[rsyslog-webui]
Collector[rsyslog Collectors] --> DB[(MySQL rsyslogdb)]
WebUI --> DB
subgraph TLS_source[TLS certificate]
  ACME[ACME DNS-01 + Cloudflare]
  Manual[Manual tls.crt + tls.key]
end
ACME -.-> Traefik
Manual -.-> Traefik</code></pre>
		<p class="small text-muted">Default ACME path: <code>docker compose --env-file .env.tls up -d --build</code> (example <code>.env.tls</code> sets <code>COMPOSE_PROFILES=tls</code>). Alternate: <code>docker-compose.tls.yml</code>. Own certs: <code>docker-compose.tls-manual.yml</code>.</p>
		<h2 class="h5 mt-3">1) DNS and certificate source</h2>
		<ul>
			<li>Create an <code>A</code> (or <code>AAAA</code>) record for your log UI host (for example <code>logs.example.com</code>).</li>
			<li>If using Cloudflare DNS-01 challenge, create an API token with <code>Zone:DNS:Edit</code> permission for the target zone.</li>
			<li>If using manual certificates, no Cloudflare API token is required.</li>
			<li>Store only token values in local env files (<code>.env.tls</code>), never commit secrets.</li>
		</ul>
		<h2 class="h5 mt-3" id="tls-automated">2) Automated TLS — Cloudflare DNS-01</h2>
		<p class="small text-muted">Let’s Encrypt certificates; Traefik completes DNS-01 challenges via Cloudflare. Requires a DNS API token.</p>
		<pre><code>cp docker/tls.env.example .env.tls
# Set TLS_DOMAIN, ACME_EMAIL, CF_DNS_API_TOKEN, and strong RSYSLOG_* secrets
# (example file includes COMPOSE_PROFILES=tls — no --profile flag needed)
docker compose --env-file .env.tls up -d --build
docker compose --env-file .env.tls ps

# If you removed COMPOSE_PROFILES=tls from .env.tls:
docker compose --env-file .env.tls --profile tls up -d --build

# Alternate full stack:
docker compose --env-file .env.tls -f docker-compose.tls.yml up -d --build</code></pre>

		<h2 class="h5 mt-4" id="tls-manual">3) Manual TLS — your own certificate (no Cloudflare API key)</h2>
		<p class="small text-muted mb-2">Use this when you obtain certificates elsewhere (commercial CA, internal PKI, cert-manager, manual ACME on another host, etc.). Files are read from the host path <code>docker/certs/</code> and mounted into Traefik.</p>
		<ul class="small mb-3">
			<li><strong><code>docker/certs/tls.crt</code></strong> — server certificate; if your CA gives a chain file, concatenate leaf + intermediate certificates into this file.</li>
			<li><strong><code>docker/certs/tls.key</code></strong> — matching private key; restrict permissions on the host (e.g. <code>chmod 600</code>).</li>
			<li><strong>Compose file:</strong> <code>docker-compose.tls-manual.yml</code> (not <code>docker-compose.tls.yml</code>).</li>
			<li><strong>Env:</strong> copy <code>docker/tls.env.example</code> to <code>.env.tls</code> for app secrets; <code>CF_DNS_API_TOKEN</code> is unused in this stack.</li>
		</ul>
		<pre><code># 1) Place PEM files:
#    docker/certs/tls.crt
#    docker/certs/tls.key
cp docker/tls.env.example .env.tls
docker compose --env-file .env.tls -f docker-compose.tls-manual.yml up -d --build
docker compose --env-file .env.tls -f docker-compose.tls-manual.yml ps</code></pre>
		<p class="small text-muted mb-3"><strong>Renewal:</strong> before the cert expires, replace <code>tls.crt</code> / <code>tls.key</code> on the host, then restart Traefik, for example: <code>docker compose --env-file .env.tls -f docker-compose.tls-manual.yml restart traefik</code>. Do not commit real keys or certs (see <code>.gitignore</code>). Short reference: <code>docker/TLS.md</code>.</p>

		<h2 class="h5 mt-3">4) Verify TLS and forwarding</h2>
		<pre><code>curl -I https://logs.example.com
curl -s https://logs.example.com/login.php | head</code></pre>
		<p class="small text-muted">Confirm you get a valid certificate, expected host, and login response.</p>
		<h2 class="h5 mt-3" id="static-caching">5) Static asset caching (Docker Apache image)</h2>
		<p class="small text-muted mb-0">The web image enables <code>mod_expires</code> and <code>mod_headers</code> via <code>docker/apache/static-cache.conf</code>. Typical static files (<code>.css</code>, <code>.js</code>, fonts, common image types) get <code>Cache-Control: public, max-age=604800</code> (7 days). PHP responses stay <code>no-store</code>. Because filenames are not content-hashed, deploy style or script changes under new names or accept that repeat visitors may need a hard refresh until the cache expires.</p>
		<h2 class="h5 mt-4" id="cloudflare-speed">6) Cloudflare proxy and Lighthouse / PageSpeed</h2>
		<p class="small text-muted mb-0">If the hostname is behind Cloudflare, <strong>Rocket Loader</strong> often appears as render-blocking in lab tests. For predictable script order and cleaner Lighthouse traces, turn Rocket Loader off for the zone (or scope a Configuration Rule to disable it for the log UI hostname). This is a CDN setting, not something the app can override.</p>
		<h2 class="h5 mt-4">7) Production hardening checklist</h2>
		<ul>
			<li>Set strong session and app secrets.</li>
			<li>Store OAuth/API secrets outside source control.</li>
			<li>Restrict MySQL and collector ports at network layer (allow only trusted sources).</li>
			<li>Enable HTTPS whitelist where required and verify forwarded headers.</li>
			<li>Enable local account MFA policy from Admin → Directory.</li>
			<li>Review Admin → Logs for sign-in/access/email anomalies.</li>
			<li>Validate backup and restore procedures.</li>
		</ul>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
