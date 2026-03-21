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
	<title>Installation - <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
	<link href="../css/docs-site.css" rel="stylesheet">
</head>
<body class="bg-body-secondary">
<div class="docs-shell">
	<?php $docsActive = 'installation'; include __DIR__ . '/../includes/partials/docs-nav.php'; ?>
	<div class="docs-content">
		<h1 class="h3">Installation</h1>
		<p class="text-muted">Docker-first setup for development and TLS production, with remote syslog ingestion and admin bootstrap.</p>

		<h2 class="h5 mt-3">Stack overview</h2>
		<ul>
			<li><strong>TLS/Production — default</strong> (<code>docker-compose.yml</code> + <code>.env.tls</code> from <code>docker/tls.env.example</code>): Traefik <code>80/443</code>, Let’s Encrypt via Cloudflare DNS-01, web also on <code>WEB_PORT</code> (default <code>8080</code>), MySQL host <code>3306</code>, collector <code>5514</code>.</li>
			<li><strong>Development</strong> (same file, no <code>.env.tls</code>): web <code>8080</code>, MySQL <code>3306</code>, collector <code>5514</code>; Traefik off.</li>
			<li><strong>TLS/Production — alternate ACME</strong> (<code>docker-compose.tls.yml</code>): same TLS idea, separate network layout.</li>
			<li><strong>TLS/Production — manual certs</strong> (<code>docker-compose.tls-manual.yml</code>): Traefik <code>80/443</code>; mount <code>docker/certs/tls.crt</code> and <code>docker/certs/tls.key</code> (no Cloudflare API token).</li>
		</ul>
		<p class="small text-muted">Collector accepts incoming network syslog only and forwards to MySQL (no demo/fake generation).</p>

		<h2 class="h5 mt-3">Development quick start</h2>
		<pre><code>docker compose up --build -d</code></pre>
		<p>Open <code>http://localhost:8080</code>. On first sign-in, create your admin username and strong password.</p>

		<h2 class="h5 mt-3">TLS / production start</h2>
		<p class="mb-2"><strong>Automated certificates (Cloudflare DNS-01) — recommended</strong></p>
		<pre><code>cp docker/tls.env.example .env.tls
# set TLS_DOMAIN, ACME_EMAIL, CF_DNS_API_TOKEN, strong secrets (COMPOSE_PROFILES=tls is in the example)
docker compose --env-file .env.tls up -d --build
docker compose --env-file .env.tls ps</code></pre>
		<p class="small text-muted mb-2">Alternate: <code>-f docker-compose.tls.yml</code>. If you drop <code>COMPOSE_PROFILES=tls</code> from <code>.env.tls</code>, add <code>--profile tls</code>.</p>
		<p class="mb-2"><strong>Manual certificates (no Cloudflare API key)</strong></p>
		<pre><code># place PEM files: docker/certs/tls.crt and docker/certs/tls.key
cp docker/tls.env.example .env.tls
docker compose --env-file .env.tls -f docker-compose.tls-manual.yml up -d --build
docker compose --env-file .env.tls -f docker-compose.tls-manual.yml ps</code></pre>
		<p class="small text-muted">Full TLS options and verification: <a href="deployment.php">Deployment and TLS</a> · <code>docker/TLS.md</code></p>

		<h2 class="h5 mt-3">Development vs production</h2>
		<ul>
			<li><strong>Development</strong>: optimized for local iteration, seeded/demo-friendly behavior, localhost endpoints.</li>
			<li><strong>Production</strong>: TLS edge, hardened secrets, explicit network controls, audit logs reviewed in Admin.</li>
			<li>Use different secrets and hostnames per environment; do not reuse dev credentials in production.</li>
		</ul>

		<h2 class="h5 mt-3">Remote sender test</h2>
		<p>From another Linux host:</p>
		<pre><code>logger -n &lt;docker-host-ip&gt; -P 5514 -d -t remote-test "hello from remote sender"</code></pre>
		<p>Verify inserts on Docker host:</p>
		<pre><code>docker exec rsyslog-webui-db-1 mysql -ursyslog -prsyslog rsyslogdb -e "SELECT ID,FromHost,SysLogTag,LEFT(Message,120) msg FROM SystemEvents ORDER BY ID DESC LIMIT 5;"</code></pre>

		<h2 class="h5 mt-3">Sender config (same port)</h2>
		<pre><code># /etc/rsyslog.d/60-rsyslog-webui.conf
*.* @&lt;docker-host-ip&gt;:5514
# TCP alternative:
# *.* @@&lt;docker-host-ip&gt;:5514

sudo systemctl restart rsyslog</code></pre>
		<p class="small text-muted">For a guided snippet generator, open Admin → Collector config.</p>

		<h2 class="h5 mt-3">Generate natural logs</h2>
		<p>On the sender host, trigger real system activity:</p>
		<pre><code>sudo systemctl restart cron || sudo systemctl restart crond
sudo -k
sudo -n true || true
sudo apt update || sudo dnf check-update || sudo yum check-update</code></pre>
		<p class="small text-muted">You can also run <code>logger -p user.err -t remote-test "rsyslog forward test"</code> for a controlled check.</p>

		<h2 class="h5 mt-3">Manual install</h2>
		<pre><code>git clone https://github.com/Tiny-Lama/rsyslog-webui.git /var/www/html/rsyslog-webui
cp /var/www/html/rsyslog-webui/config-template.php /var/www/html/rsyslog-webui/config.php
composer install --no-dev</code></pre>
		<p>Configure DB settings in <code>config.php</code> and ensure rsyslog writes to <code>SystemEvents</code>. Use Admin <code>Maintenance</code> to control live retention and cleanup behavior.</p>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
