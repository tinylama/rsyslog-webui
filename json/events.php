<?php
	include '../config.php';

	header('Content-Type: application/json');
	
	$wherestring = "";
	if($_GET["info"] == "0") { $wherestring = " WHERE Priority != 6 "; }
	if($_GET["notice"] == "0") { if($wherestring == "" ) { $wherestring = " WHERE Priority != 5 "; } else { $wherestring = $wherestring."AND Priority != 5 "; } }
	if($_GET["debug"] == "0") { if($wherestring == "" ) { $wherestring = " WHERE Priority != 7 "; } else { $wherestring = $wherestring."AND Priority != 7 "; } }
	if($_GET["err"] == "0") { if($wherestring == "" ) { $wherestring = " WHERE Priority != 3 "; } else { $wherestring = $wherestring."AND Priority != 3 "; } }
	if($_GET["warning"] == "0") { if($wherestring == "" ) { $wherestring = " WHERE Priority != 4 "; } else { $wherestring = $wherestring."AND Priority != 4 "; } }

	$mysqli = new mysqli($mysql_server, $mysql_user, $mysql_password, $mysql_database);
	if ($mysqli->connect_errno) {
		echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
	}
	
	$sth = $mysqli->query("SELECT *, LEFT(Message, 120) AS SmallMessage FROM SystemEvents $wherestring ORDER BY ID DESC LIMIT 100");
	$rows = array();
	while($r = mysqli_fetch_assoc($sth)) {
		$rows[] = $r;
	}
	
	//mysqli_close($mysqli);
	
	echo json_encode($rows);

?>