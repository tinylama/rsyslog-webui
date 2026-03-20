<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/events-search-where.php';
require_once __DIR__ . '/../includes/log-maintenance.php';

require_login(true);

header('Content-Type: application/json');

$query = 'SELECT *, LEFT(Message, 120) AS SmallMessage FROM SystemEvents ';

$searchstring = isset($_GET['search']) ? (string) $_GET['search'] : '';
$keywordstring = isset($_GET['q']) ? (string) $_GET['q'] : '';
$sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 2000;
$limit = $limit < 1 ? 2000 : $limit;
$limit = $limit > 2000 ? 2000 : $limit;

$built = build_systemevents_search_where($searchstring, $keywordstring);
if (!$built['ok']) {
	http_response_code(400);
	echo json_encode(['error' => $built['error'], 'detail' => $built['detail'] ?? null]);
	exit;
}

$wherestring = $built['where'];
$qArray = $built['params'];

// Hide demo/fixture rows in non-dev environments so production only shows real logs.
if (!auth_is_running_in_docker_dev()) {
	$demoExclusion = "NOT ((FromHost = 'host-1' AND SysLogTag = 'sshd') OR (FromHost = 'host-2' AND SysLogTag = 'nginx') OR (FromHost = 'host-3' AND SysLogTag = 'systemd') OR (FromHost = 'host-4' AND SysLogTag = 'cron') OR (FromHost = 'host-5' AND SysLogTag = 'rsyslog'))";
	if ($wherestring === '') {
		$wherestring = ' WHERE ' . $demoExclusion . ' ';
	} else {
		$wherestring .= ' AND ' . $demoExclusion . ' ';
	}
}

$db = new PDO(
	"mysql:host=$mysql_server;dbname=$mysql_database;charset=utf8",
	$mysql_user,
	$mysql_password
);
log_maintenance_maybe_run_live($db);

$rows = [];

if ($sinceId > 0) {
	if ($wherestring === '') {
		$wherestring = ' WHERE ID > :since_id ';
	} else {
		$wherestring .= ' AND ID > :since_id ';
	}
	$qArray['since_id'] = $sinceId;
}

$stmt = $db->prepare($query . $wherestring . ' ORDER BY ID DESC LIMIT ' . (int) $limit);

/**
 * Normalize messages so severity prefixes like "error:" / "info:" don't
 * duplicate the separate Priority/Severity column in the UI.
 */
function auth_clean_message_prefix(string $msg): string
{
	$clean = $msg;
	// Common patterns from our demo + typical loggers: "error: ...", "info: ...", etc.
	$clean = preg_replace(
		'/^(?:emerg(?:ency)?|alert|crit(?:ical)?|err(?:or)?|warning|warn(?:ing)?|notice|info|debug):\s*/i',
		'',
		$clean
	);
	return is_string($clean) ? $clean : $msg;
}

if ($stmt->execute($qArray)) {
	foreach ($stmt as $row) {
		if (isset($row['Message']) && is_string($row['Message'])) {
			$row['Message'] = auth_clean_message_prefix($row['Message']);
		}
		// Recompute the truncated version from the cleaned message.
		if (isset($row['Message']) && is_string($row['Message'])) {
			$row['SmallMessage'] = mb_substr($row['Message'], 0, 120);
		}
		$rows[] = $row;
	}
}

echo json_encode($rows);
