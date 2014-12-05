<?php
	include '../../config.php';
	
	header('Content-Type: application/json');
	
	function makeJSONArray($value)
	{
		$indexedOnly = array();
		$indexedOnly[] = array($value);
		return $indexedOnly;
	}

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
	
	$sth = $mysqli->query("SELECT Priority, COUNT(ID) AS Qty FROM rsyslogdb.SystemEvents GROUP BY Priority ORDER BY Priority ASC");

	$items = array();
	$dataProvider = array();
	$i = 0;
	while( $row = mysqli_fetch_assoc($sth) ) {
		
		$prio = "";
		
		if( $row["Priority"] == "0" ) { $prio = "EMERGENCY"; $color = "#d9534f"; }; 
		if( $row["Priority"] == "1" ) { $prio = "ALERT"; $color = "#d9534f"; }; 
		if( $row["Priority"] == "2" ) { $prio = "CRITICAL"; $color = "#d9534f"; }; 
		if( $row["Priority"] == "3" ) { $prio = "ERROR"; $color = "#d9534f"; }; 
		if( $row["Priority"] == "4" ) { $prio = "WARNING"; $color = "#f0ad4e"; }; 
		if( $row["Priority"] == "5" ) { $prio = "NOTICE"; $color = "#5cb85c"; }; 
		if( $row["Priority"] == "6" ) { $prio = "INFO"; $color = "#5bc0de"; }; 
		if( $row["Priority"] == "7" ) { $prio = "DEBUG"; $color = "#337ab7"; }; 
		
		array_push($dataProvider, array('Severity' => $prio, 'Messages' => intval($row["Qty"]), 'color' => $color));
	}
	
	$arr = array( 'type' => 'pie', 'theme' => 'none', 'legend' => array( "markerType"=> "circle", "position" => "right", "marginRight" => 80, "autoMargins" => false), 'dataProvider' => $dataProvider );
	
	$arr[ "valueField" ] = "Messages";
	$arr[ "titleField" ] = "Severity";
	$arr[ "colorField" ] = "color";
	$arr[ "balloonText" ] = "[[title]]<br><span style='font-size:14px'><b>[[value]]</b> ([[percents]]%)</span>";

	echo json_encode($arr);

?>