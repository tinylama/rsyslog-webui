<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_admin();

$me = auth_current_user();
$displayName = is_array($me) ? (string) ($me['display_name'] ?? '') : '';
$navAdminActive = 'logs';
$siteTitle = htmlspecialchars($site_name ?? 'System Logs', ENT_QUOTES, 'UTF-8');

$pdo = auth_db();
auth_ensure_audit_schema($pdo);

$type = isset($_GET['type']) ? (string) $_GET['type'] : 'signin';
if (!in_array($type, ['signin', 'access', 'email'], true)) {
	$type = 'signin';
}
$q = trim((string) ($_GET['q'] ?? ''));
$limit = (int) ($_GET['limit'] ?? 200);
$limit = max(25, min(500, $limit));

$where = '';
$params = [];
if ($q !== '') {
	$like = '%' . $q . '%';
	if ($type === 'signin') {
		$where = ' WHERE Username LIKE :q OR ClientIP LIKE :q OR Outcome LIKE :q OR Reason LIKE :q OR Provider LIKE :q ';
	} elseif ($type === 'access') {
		$where = ' WHERE Area LIKE :q OR ClientIP LIKE :q OR Decision LIKE :q OR PolicySource LIKE :q OR Detail LIKE :q ';
	} else {
		$where = ' WHERE EventType LIKE :q OR Recipient LIKE :q OR Status LIKE :q OR ProviderMessage LIKE :q ';
	}
	$params[':q'] = $like;
}

$rows = [];
$rateLabel = '-';
$rateValue = 0.0;
$emptyColspan = $type === 'email' ? 5 : 7;
if ($type === 'signin') {
	$sql = 'SELECT ID, CreatedAt, Username, Provider, Outcome, Reason, ClientIP, Source FROM SignInLogs'
		. $where
		. ' ORDER BY ID DESC LIMIT ' . (int) $limit;
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($type === 'access') {
	$sql = 'SELECT ID, CreatedAt, Area, RoutePath, ClientIP, Decision, PolicySource, Detail FROM AccessLogs'
		. $where
		. ' ORDER BY ID DESC LIMIT ' . (int) $limit;
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
	$sql = 'SELECT ID, CreatedAt, EventType, Recipient, Status, ProviderMessage FROM EmailLogs'
		. $where
		. ' ORDER BY ID DESC LIMIT ' . (int) $limit;
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Average ingest rate over the last hour (count / 3600s). Avoids "-" when all rows share one timestamp.
try {
	$rateStmt = $pdo->query(
		'SELECT COUNT(*) AS c FROM SystemEvents
		 WHERE ReceivedAt >= (NOW() - INTERVAL 1 HOUR)'
	);
	$rateRow = $rateStmt ? $rateStmt->fetch(PDO::FETCH_ASSOC) : null;
	$countHour = (int) ($rateRow['c'] ?? 0);
	if ($countHour > 0) {
		$perSec = $countHour / 3600.0;
		if ($perSec >= 1.0) {
			$rateLabel = 'logs/sec';
			$rateValue = $perSec;
		} else {
			$rateLabel = 'logs/hour';
			$rateValue = (float) $countHour;
		}
	}
} catch (Throwable $e) {
	$rateLabel = '-';
	$rateValue = 0.0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Admin logs - <?php echo $siteTitle; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="../css/custom.css" rel="stylesheet">
	<link href="../css/layout.css" rel="stylesheet">
	<link href="../css/dark-mode.css" rel="stylesheet">
</head>
<body>
<div class="app-shell py-3 py-md-4">
	<?php include __DIR__ . '/../includes/partials/navbar-admin.php'; ?>

	<div class="row g-3 mb-4">
		<div class="col-12 col-md-8">
			<h1 class="h3 mb-1">Audit logs</h1>
			<p class="text-muted mb-0 small">Track sign-in outcomes, access decisions, and outbound email events.</p>
		</div>
		<div class="col-12 col-md-4">
			<div class="card border-0 bg-body-secondary h-100">
				<div class="card-body d-flex align-items-center justify-content-between">
					<span class="text-muted small">Ingest rate (1h)</span>
					<span class="fs-5 fw-semibold"><?php echo $rateValue > 0 ? number_format($rateValue, 2) . ' ' . htmlspecialchars($rateLabel, ENT_QUOTES, 'UTF-8') : '-'; ?></span>
				</div>
			</div>
		</div>
	</div>

	<div class="card border-0 shadow-sm mb-4">
		<div class="card-body p-3">
			<form method="get" class="row g-2 align-items-end">
				<div class="col-12 col-md-3">
					<label class="form-label small" for="type">Log type</label>
					<select class="form-select form-select-sm" id="type" name="type">
						<option value="signin" <?php echo $type === 'signin' ? 'selected' : ''; ?>>Sign-in logs</option>
						<option value="access" <?php echo $type === 'access' ? 'selected' : ''; ?>>Access logs</option>
						<option value="email" <?php echo $type === 'email' ? 'selected' : ''; ?>>Email logs</option>
					</select>
				</div>
				<div class="col-12 col-md-5">
					<label class="form-label small" for="q">Search</label>
					<input class="form-control form-control-sm" id="q" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" placeholder="IP, user, status, reason..." />
				</div>
				<div class="col-6 col-md-2">
					<label class="form-label small" for="limit">Rows</label>
					<input class="form-control form-control-sm" id="limit" name="limit" type="number" min="25" max="500" value="<?php echo $limit; ?>" />
				</div>
				<div class="col-6 col-md-2 d-grid">
					<button type="submit" class="btn btn-primary btn-sm">Apply</button>
				</div>
			</form>
		</div>
	</div>

	<div class="card border-0 shadow-sm">
		<div class="card-body p-0">
			<div class="table-responsive">
				<table class="table table-striped table-hover align-middle small mb-0">
					<thead class="table-light">
						<tr>
							<?php if ($type === 'signin'): ?>
								<th>Time</th><th>User</th><th>Provider</th><th>Outcome</th><th>Reason</th><th>IP</th><th>Source</th>
							<?php elseif ($type === 'access'): ?>
								<th>Time</th><th>Area</th><th>Route</th><th>Decision</th><th>Policy</th><th>Detail</th><th>IP</th>
							<?php else: ?>
								<th>Time</th><th>Event</th><th>Recipient</th><th>Status</th><th>Provider detail</th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
					<?php if ($rows === []): ?>
						<tr><td colspan="<?php echo (int) $emptyColspan; ?>" class="text-muted">No rows found.</td></tr>
					<?php endif; ?>
					<?php foreach ($rows as $r): ?>
						<tr>
							<?php if ($type === 'signin'): ?>
								<td><?php echo htmlspecialchars((string) ($r['CreatedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($r['Username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($r['Provider'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><span class="badge <?php echo (($r['Outcome'] ?? '') === 'allowed') ? 'bg-success' : 'bg-danger'; ?>"><?php echo htmlspecialchars((string) ($r['Outcome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
								<td><?php echo htmlspecialchars((string) ($r['Reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($r['ClientIP'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($r['Source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
							<?php elseif ($type === 'access'): ?>
								<td><?php echo htmlspecialchars((string) ($r['CreatedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($r['Area'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($r['RoutePath'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><span class="badge <?php echo (($r['Decision'] ?? '') === 'allowed') ? 'bg-success' : 'bg-danger'; ?>"><?php echo htmlspecialchars((string) ($r['Decision'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
								<td><?php echo htmlspecialchars((string) ($r['PolicySource'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($r['Detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($r['ClientIP'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
							<?php else: ?>
								<?php
								$st = strtolower((string) ($r['Status'] ?? ''));
								$emailBadge = 'bg-secondary';
								if ($st === 'sent') {
									$emailBadge = 'bg-success';
								} elseif ($st === 'failed' || str_starts_with($st, 'fail')) {
									$emailBadge = 'bg-danger';
								}
								?>
								<td><?php echo htmlspecialchars((string) ($r['CreatedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($r['EventType'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($r['Recipient'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><span class="badge <?php echo $emailBadge; ?>"><?php echo htmlspecialchars((string) ($r['Status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
								<td><?php echo htmlspecialchars((string) ($r['ProviderMessage'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/dark-mode-switch.js"></script>
</body>
</html>
