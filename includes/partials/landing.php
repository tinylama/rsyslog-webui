<?php
/**
 * Public marketing landing. Included from index.php when auth on and not signed in.
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../azure-oidc.php';

$site = htmlspecialchars((string) ($site_name ?? 'System Logs'), ENT_QUOTES, 'UTF-8');
$landingMetaDescription = 'Search and tail syslog with live updates, severity filters, and role-based access. Optional Microsoft Entra ID sign-in, MFA, and operator documentation.';
$currentUser = auth_is_enabled() ? auth_current_user() : null;
$signedIn = auth_is_enabled() && $currentUser !== null;
$displayName = is_array($currentUser) ? (string) ($currentUser['display_name'] ?? '') : '';
$isAdmin = is_array($currentUser) && (($currentUser['role'] ?? '') === 'admin');
$azureReady = auth_azure_enabled() && auth_azure_is_configured();
$devMode = auth_is_running_in_docker_dev();
$docsBase = 'docs';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="<?php echo htmlspecialchars($landingMetaDescription, ENT_QUOTES, 'UTF-8'); ?>">
	<title><?php echo $site; ?> — Centralized syslog search</title>
	<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
	<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
	<!-- Bootstrap must load before app CSS: grid/typography/buttons are needed for FCP/LCP (async CSS regressed lab scores). -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="css/vendor/bootstrap-icons.min.css" rel="stylesheet">
	<!-- Single first-party sheet: layout + theme + landing (fewer round-trips than three separate files). -->
	<link href="css/landing-public.css" rel="stylesheet">
	<script>
	(function () {
		try {
			if (localStorage.getItem('darkSwitch') === 'dark') {
				document.documentElement.setAttribute('data-bs-theme', 'dark');
			}
		} catch (e) {}
	})();
	</script>
</head>
<body class="bg-body-secondary">
<div class="app-shell py-4 py-md-5">
	<?php if ($signedIn): ?>
	<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-4 app-navbar" aria-label="Account">
		<div class="container-fluid px-0 px-sm-2 align-items-center">
			<a class="navbar-brand flex-shrink-0" href="./" title="Home"><?php echo $site; ?></a>
			<ul class="navbar-nav ms-auto flex-shrink-0">
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
						<i class="bi bi-person-circle fs-5" aria-hidden="true"></i>
						<span class="text-truncate" style="max-width: 10rem"><?php echo htmlspecialchars($displayName !== '' ? $displayName : 'Account', ENT_QUOTES, 'UTF-8'); ?></span>
					</a>
					<ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="userMenu">
						<li><a class="dropdown-item" href="account/security.php"><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Account security</a></li>
						<?php if ($isAdmin): ?>
							<li><a class="dropdown-item" href="admin/users.php"><i class="bi bi-people me-2" aria-hidden="true"></i>Admin</a></li>
						<?php endif; ?>
						<li><hr class="dropdown-divider"></li>
						<li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</a></li>
					</ul>
				</li>
			</ul>
		</div>
	</nav>
	<?php endif; ?>

	<main id="main-content">
	<div class="landing-hero">
		<div class="row align-items-center g-4">
			<div class="col-lg-7">
				<h1 class="display-5 mb-3"><?php echo $site; ?></h1>
				<p class="lead mb-4">Search and tail syslog events with live updates, severity breakdown, and role-based access. Optional Microsoft Entra ID sign-in.</p>
				<?php if (!$signedIn): ?>
					<div class="d-flex flex-wrap gap-2">
						<a class="btn btn-light btn-lg px-4" href="login.php?next=%2F"><i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Sign in</a>
						<?php if ($azureReady): ?>
							<a class="btn btn-outline-light btn-lg px-4" href="auth/azure/start.php?next=%2F"><i class="bi bi-microsoft me-2" aria-hidden="true"></i>Microsoft</a>
						<?php endif; ?>
						<a class="btn btn-outline-light btn-lg px-4" href="<?php echo htmlspecialchars($docsBase . '/index.php', ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-book me-2" aria-hidden="true"></i>Documentation</a>
					</div>
				<?php else: ?>
					<div class="d-flex flex-wrap gap-2 align-items-center">
						<span class="badge text-bg-light fw-semibold">
							Signed in as <?php echo htmlspecialchars($displayName !== '' ? $displayName : 'Account', ENT_QUOTES, 'UTF-8'); ?>
						</span>
					</div>
				<?php endif; ?>
			</div>
			<div class="col-lg-5 text-lg-end d-none d-lg-block">
				<i class="bi bi-journal-text display-1 opacity-50" aria-hidden="true"></i>
			</div>
		</div>
	</div>

	<div class="row g-3 g-md-4">
		<div class="col-md-6 col-xl-3">
			<div class="landing-feature">
				<i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
				<h2 class="h6 fw-semibold">Live search</h2>
				<p class="small text-muted mb-0">Filter message, host, and tag as you type. Advanced filterset syntax for precise queries.</p>
			</div>
		</div>
		<div class="col-md-6 col-xl-3">
			<div class="landing-feature">
				<i class="bi bi-broadcast d-block mb-2" aria-hidden="true"></i>
				<h2 class="h6 fw-semibold">Live tail</h2>
				<p class="small text-muted mb-0">Auto-refresh on a short interval so new lines appear without manual reload.</p>
			</div>
		</div>
		<div class="col-md-6 col-xl-3">
			<div class="landing-feature">
				<i class="bi bi-shield-lock d-block mb-2" aria-hidden="true"></i>
				<h2 class="h6 fw-semibold">Access control</h2>
				<p class="small text-muted mb-0">Admin directory, optional MFA and passkeys, Entra SSO when configured.</p>
			</div>
		</div>
		<div class="col-md-6 col-xl-3">
			<div class="landing-feature">
				<i class="bi bi-phone d-block mb-2" aria-hidden="true"></i>
				<h2 class="h6 fw-semibold">Responsive</h2>
				<p class="small text-muted mb-0">Layout tuned from phones to wide desktops.</p>
			</div>
		</div>
	</div>

	<section id="documentation" class="landing-docs mt-4 mt-md-5">
		<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
			<div>
				<h2 class="h4 mb-1">Documentation</h2>
				<p class="text-muted mb-0 small">Setup, security, deployment, and testing guides for operators and developers.</p>
			</div>
			<a class="btn btn-sm btn-primary landing-docs-cta" href="<?php echo htmlspecialchars($docsBase . '/index.php', ENT_QUOTES, 'UTF-8'); ?>">
				<i class="bi bi-journal-text me-1" aria-hidden="true"></i>Open docs index
			</a>
		</div>
		<div class="row g-3 g-md-4">
			<div class="col-12 col-md-6 col-xl-3">
				<a class="landing-doc-card text-decoration-none" href="<?php echo htmlspecialchars($docsBase . '/installation.php', ENT_QUOTES, 'UTF-8'); ?>">
					<i class="bi bi-rocket-takeoff d-block mb-2" aria-hidden="true"></i>
					<h3 class="h6 fw-semibold mb-1">Quick start</h3>
					<p class="small text-muted mb-0">Docker and manual installation paths with first-run steps.</p>
				</a>
			</div>
			<div class="col-12 col-md-6 col-xl-3">
				<a class="landing-doc-card text-decoration-none" href="<?php echo htmlspecialchars($docsBase . '/authentication.php', ENT_QUOTES, 'UTF-8'); ?>">
					<i class="bi bi-shield-lock d-block mb-2" aria-hidden="true"></i>
					<h3 class="h6 fw-semibold mb-1">Authentication and MFA</h3>
					<p class="small text-muted mb-0">Local auth, Entra sign-in, TOTP, passkeys, and challenge flow.</p>
				</a>
			</div>
			<div class="col-12 col-md-6 col-xl-3">
				<a class="landing-doc-card text-decoration-none" href="<?php echo htmlspecialchars($docsBase . '/deployment.php', ENT_QUOTES, 'UTF-8'); ?>">
					<i class="bi bi-cloud-lock d-block mb-2" aria-hidden="true"></i>
					<h3 class="h6 fw-semibold mb-1">Production deployment</h3>
					<p class="small text-muted mb-0">TLS, reverse proxy guidance, and hardening checklist.</p>
				</a>
			</div>
			<div class="col-12 col-md-6 col-xl-3">
				<a class="landing-doc-card text-decoration-none" href="<?php echo htmlspecialchars($docsBase . '/testing.php', ENT_QUOTES, 'UTF-8'); ?>">
					<i class="bi bi-check2-square d-block mb-2" aria-hidden="true"></i>
					<h3 class="h6 fw-semibold mb-1">Testing and verification</h3>
					<p class="small text-muted mb-0">Playwright smoke and regression test execution patterns.</p>
				</a>
			</div>
		</div>
	</section>

	<?php if ($devMode): ?>
	<div class="card border-0 shadow-sm mt-4">
		<div class="card-body p-4">
			<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
				<div>
					<h2 class="h5 mb-1">Example system logs demo</h2>
					<p class="text-muted small mb-0">Static demo (no live updates). Filters and expandable rows work like the real log viewer.</p>
				</div>
				<div class="d-flex gap-2 flex-wrap">
					<input id="landingDemoSearch" class="form-control form-control-sm" style="min-width: 220px" placeholder="Search message, host, tag..." type="search" autocomplete="off" />
				</div>
			</div>

			<div class="d-flex gap-2 flex-wrap align-items-center mb-3">
				<button type="button" class="btn btn-sm btn-outline-secondary" data-sev="ALL">All</button>
				<button type="button" class="btn btn-sm btn-outline-secondary" data-sev="DEBUG">Debug</button>
				<button type="button" class="btn btn-sm btn-outline-secondary" data-sev="INFO">Info</button>
				<button type="button" class="btn btn-sm btn-outline-secondary" data-sev="WARNING">Warning</button>
				<button type="button" class="btn btn-sm btn-outline-secondary" data-sev="ERROR">Error</button>
			</div>

			<div class="table-responsive">
				<table class="table table-sm table-striped align-middle mb-0 small">
					<thead class="table-light">
						<tr>
							<th>Time</th>
							<th style="width: 92px">Severity</th>
							<th style="width: 170px">Host</th>
							<th style="width: 140px">Tag</th>
							<th>Message</th>
						</tr>
					</thead>
					<tbody id="landingDemoTbody"></tbody>
				</table>
			</div>

			<div class="form-text mt-3">Tip: click a row to expand the full message.</div>
		</div>
	</div>

	<script>
		(function () {
			// Static example dataset for the landing page demo.
			// No polling / no network requests.
			const demoLogs = [
				// Demo messages intentionally omit the "error:/info:/warn:/debug:" prefixes so it looks like real log text.
				{ id: 1, t: '2026-03-20T10:02:11', sev: 'ERROR', host: 'host-1', tag: 'sshd', msg: 'Connection refused again, retrying... (nginx upstream 1)' , facility: 'authpriv' },
				{ id: 2, t: '2026-03-20T10:02:27', sev: 'INFO', host: 'host-2', tag: 'rsyslog', msg: 'message received and queued for MySQL insert', facility: 'daemon' },
				{ id: 3, t: '2026-03-20T10:02:33', sev: 'WARNING', host: 'host-3', tag: 'cron', msg: 'scheduling delay detected; next run adjusted', facility: 'cron' },
				{ id: 4, t: '2026-03-20T10:02:45', sev: 'DEBUG', host: 'host-4', tag: 'kernel', msg: 'omysql write completed in 12ms; rows affected: 25', facility: 'kern' },
				{ id: 5, t: '2026-03-20T10:03:02', sev: 'ERROR', host: 'host-2', tag: 'systemd', msg: 'Failed to connect to remote syslog relay; will retry in 5s', facility: 'daemon' },
				{ id: 6, t: '2026-03-20T10:03:18', sev: 'INFO', host: 'host-1', tag: 'nginx', msg: 'nginx access log parsed; inserted into SystemEvents', facility: 'http' },
				{ id: 7, t: '2026-03-20T10:03:41', sev: 'WARNING', host: 'host-5', tag: 'apt', msg: 'package mirror slow; switching to alternate endpoint', facility: 'user' },
				{ id: 8, t: '2026-03-20T10:04:05', sev: 'DEBUG', host: 'host-3', tag: 'rsyslog', msg: 'filterset matched 1 event; enrichment=hostname', facility: 'daemon' },
			];

			const tbody = document.getElementById('landingDemoTbody');
			const search = document.getElementById('landingDemoSearch');
			const sevButtons = Array.from(document.querySelectorAll('[data-sev]'));

			let selectedSev = 'ALL';

			function escapeHtml(s) {
				return String(s).replace(/[&<>"']/g, function (c) {
					return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] || c;
				});
			}

			function normalizeQuery(q) {
				return String(q || '').trim().toLowerCase();
			}

			function matches(log, q) {
				if (!q) return true;
				const hay = (log.sev + ' ' + log.host + ' ' + log.tag + ' ' + log.facility + ' ' + log.msg).toLowerCase();
				return hay.indexOf(q) !== -1;
			}

			function render() {
				const q = normalizeQuery(search ? search.value : '');
				const filtered = demoLogs.filter(l => (selectedSev === 'ALL' || l.sev === selectedSev) && matches(l, q));

				tbody.innerHTML = '';
				if (filtered.length === 0) {
					const tr = document.createElement('tr');
					tr.innerHTML = '<td colspan="5" class="text-muted">No matching example logs.</td>';
					tbody.appendChild(tr);
					return;
				}

				for (const l of filtered) {
					const tr = document.createElement('tr');
					tr.style.cursor = 'pointer';
					tr.dataset.rowId = String(l.id);
					tr.innerHTML =
						'<td>' + escapeHtml(l.t) + '</td>' +
						'<td>' + escapeHtml(l.sev) + '</td>' +
						'<td>' + escapeHtml(l.host) + '</td>' +
						'<td>' + escapeHtml(l.tag) + '</td>' +
						'<td>' + escapeHtml(l.msg) + '</td>';
					tr.addEventListener('click', function () {
						const expanded = tr.classList.contains('expanded');
						const next = tr.nextElementSibling;
						if (expanded && next && next.classList.contains('landingDemoExpanded')) {
							next.remove();
							tr.classList.remove('expanded');
							return;
						}
						// Remove any previous expansions for a clean demo.
						const existing = tbody.querySelectorAll('tr.landingDemoExpanded');
						existing.forEach(n => n.remove());
						tr.classList.add('expanded');
						const exp = document.createElement('tr');
						exp.className = 'landingDemoExpanded';
						exp.innerHTML = '<td colspan="5"><div class="increase-font-size">' + escapeHtml(l.msg) + '</div></td>';
						tr.parentNode.insertBefore(exp, tr.nextSibling);
					});
					tbody.appendChild(tr);
				}
			}

			function setSev(sev) {
				selectedSev = sev;
				for (const b of sevButtons) {
					const isActive = b.getAttribute('data-sev') === sev;
					b.classList.toggle('btn-primary', isActive);
					b.classList.toggle('btn-outline-secondary', !isActive);
				}
				render();
			}

			sevButtons.forEach(b => b.addEventListener('click', function () {
				setSev(b.getAttribute('data-sev') || 'ALL');
			}));

			if (search) search.addEventListener('input', function () { render(); });

			// Initial render.
			setSev('ALL');
		})();
	</script>
	<?php endif; ?>

	</main>

	<footer class="landing-footer">
		<?php if ($signedIn): ?>
			Use the account menu to sign out.
		<?php else: ?>
			Sign in to open the log viewer. Local accounts or Microsoft Entra ID where enabled.
		<?php endif; ?>
	</footer>
</div>
<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="js/dark-mode-switch.js"></script>
</body>
</html>
