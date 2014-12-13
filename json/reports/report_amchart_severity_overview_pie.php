<?php
	include '../../config.php';
	
	header('Content-Type: application/json');

	$db = new PDO( "mysql:host=$mysql_server;dbname=$mysql_database;charset=utf8", $mysql_user, $mysql_password );
	
	$items = array();
	$dataProvider = array();
	$i = 0;
	
	$stmt = $db->prepare("SELECT Priority, COUNT(ID) AS Qty FROM rsyslogdb.SystemEvents GROUP BY Priority ORDER BY Priority DESC");

	if( $stmt->execute() )
	foreach( $stmt as $row ) {
	//while( $row = mysqli_fetch_assoc($sth) ) {
		
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
	$arr[ "exportConfig" ] = array( 'menuItems' => array( array( 'icon' => '../../images/export.png', 'format' => 'png' ) ) );


	echo json_encode($arr);

?>