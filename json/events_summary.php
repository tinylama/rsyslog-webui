<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/events-search-where.php';
require_once __DIR__ . '/../includes/log-maintenance.php';

require_login(true);

header('Content-Type: application/json');

$query = 'SELECT Priority, COUNT(ID) AS Qty FROM ';

$searchstring = isset($_GET['search']) ? (string) $_GET['search'] : '';
$keywordstring = isset($_GET['q']) ? (string) $_GET['q'] : '';

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

$table_query = '(SELECT ID, Priority FROM SystemEvents ' . $wherestring . ' ORDER BY ID DESC LIMIT 2000) as tmp ';

$items = [];
$stmt = $db->prepare($query . $table_query . ' GROUP BY Priority');

if ($stmt->execute($qArray)) {
	foreach ($stmt as $row) {
		array_push($items, [$row['Priority'], (int) $row['Qty']]);
	}
}

echo json_encode($items);
