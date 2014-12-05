<?php
	include '../config.php';
	
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
	
	$sth = $mysqli->query("SELECT Priority, COUNT(ID) AS Qty FROM rsyslogdb.SystemEvents GROUP BY Priority");

	//$res = "{\"label\": \"test\", \"data\": [";
	
	$items = array();
	$ticks = array();
	$i = 0;
	while( $row = mysqli_fetch_assoc($sth) ) {
		
		$prio = "";
		
		if( $row["Priority"] == "0" ) $prio = "EMERGENCY"; 
		if( $row["Priority"] == "1" ) $prio = "ALERT"; 
		if( $row["Priority"] == "2" ) $prio = "CRITICAL"; 
		if( $row["Priority"] == "3" ) $prio = "ERROR"; 
		if( $row["Priority"] == "4" ) $prio = "WARNING"; 
		if( $row["Priority"] == "5" ) $prio = "NOTICE"; 
		if( $row["Priority"] == "6" ) $prio = "INFO"; 
		if( $row["Priority"] == "7" ) $prio = "DEBUG"; 
		
//		array_push($items, array("data" => array(array($row["Priority"], intval($row["Qty"])))));
		//array_push($items, array('label' => $prio, 'data' => array($row["Priority"], intval($row["Qty"]))));
		array_push($items, array($row["Priority"], intval($row["Qty"])));
		//array_push($ticks, array(intval($row["Priority"]), $prio));
		//array_push($items, array('label' => $prio, 'data' => array($prio, intval($row["Qty"]))));
		//array_push($items, array('label' => $prio, 'data' => intval($row["Qty"])));
	}

//	$arr = array('label' => 'test', 'data' => $items, 'ticks' => $ticks);
//	$arr = array('label' => 'test', 'data' => $items);
	//$arr = array('label' => 'test', 'data' => $items);
	
	//$res = $res."]}";
	
	//echo $res;
	//mysqli_close($mysqli);
	
	//echo json_encode($items);
	echo json_encode($items);

?>