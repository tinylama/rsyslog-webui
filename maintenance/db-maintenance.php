<?php
	include '../config.php';
	
	$query = "DELETE FROM rsyslogdb.SystemEvents WHERE ReceivedAt < CURDATE() - INTERVAL ".$keep_logs_for_days." day";
	
	// database connection
	$db = new PDO( "mysql:host=$mysql_server;dbname=$mysql_database;charset=utf8", $mysql_user, $mysql_password );
	$stmt = $db->prepare($query);
	$stmt->execute();
	
	//echo $query;
?>
