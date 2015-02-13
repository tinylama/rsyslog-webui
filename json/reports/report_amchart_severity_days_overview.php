<?php
	include '../../config.php';
	
	header('Content-Type: application/json');

	$db = new PDO( "mysql:host=$mysql_server;dbname=$mysql_database;charset=utf8", $mysql_user, $mysql_password );

	$items = array();
	$dataProvider = array();
	$dateentry = array();
	$i = 0;
	$date = "";
	
	$stmt = $db->prepare('SELECT Priority, DATE_FORMAT(DeviceReportedTime,\'%Y-%m-%d\') AS Date, count(ID) AS Qty FROM SystemEvents GROUP BY Date, Priority');

	if( $stmt->execute() )
	foreach( $stmt as $row ) {
		
		$prio = "";
		
		if( $row["Priority"] == "0" ) { $prio = "EMERGENCY"; $color = "#e9635e"; }; 
		if( $row["Priority"] == "1" ) { $prio = "ALERT"; $color = "#e45d59"; }; 
		if( $row["Priority"] == "2" ) { $prio = "CRITICAL"; $color = "#de5854"; }; 
		if( $row["Priority"] == "3" ) { $prio = "ERROR"; $color = "#d9534f"; }; 
		if( $row["Priority"] == "4" ) { $prio = "WARNING"; $color = "#f0ad4e"; }; 
		if( $row["Priority"] == "5" ) { $prio = "NOTICE"; $color = "#5cb85c"; }; 
		if( $row["Priority"] == "6" ) { $prio = "INFO"; $color = "#5bc0de"; }; 
		if( $row["Priority"] == "7" ) { $prio = "DEBUG"; $color = "#337ab7"; }; 
		
		if( $date == $row["Date"] ) 
		{
			$dateentry[ $prio ] = intval( $row[ 'Qty' ] );
			$dateentry[ "c".$prio ] = $color;
		}
		else
		{
			if( count( $dateentry ) != 0 )
				array_push( $dataProvider, $dateentry );
			
			$dateentry = null;
			$dateentry = array( 'Date' => $row["Date"], $prio => intval($row["Qty"]), "c".$prio => $color );
			$date = $row["Date"];
		}
		//array_push($dataProvider, array('Severity' => $prio, 'Messages' => intval($row["Qty"]), 'date' => $row["Date"]));
	}

	$valueAxes = array( 'stackType' => 'regular', 'gridAlpha' => 0.7, 'position' => 'left', 'title' => 'Messages' );
	$legendValues = array( 'equalWidths' => false, 'periodValueText' => 'total: [[value.sum]]', 'position' => 'top', 'valueAlign' => 'left', 'valueWidth' => 100 );
	
	$arr = array( 'type' => 'serial', 'theme' => 'none', 'legend' => $legendValues, 'dataProvider' => $dataProvider );
	
	$arr[ "valueAxes" ] = array($valueAxes);
	$arr[ "graphs" ] = array(array( "balloonText" => "DEBUG",
		"fillAlphas" => 0.8,
		"lineAlpha" => 0.4,
		"title" => "DEBUG",
		"labelColorField" => 'cDEBUG',
		"valueField" => "DEBUG",
		"colorField" => "cDEBUG", 
		"fillColorsField" => "cDEBUG" ), array( "balloonText" => "INFO",
		"fillAlphas" => 0.8,
		"lineAlpha" => 0.4,
		"title" => "INFO",
		"valueField" => "INFO",
		"colorField" => "cINFO", 
		"fillColorsField" => "cINFO" ), array( "balloonText" => "NOTICE",
		"fillAlphas" => 0.8,
		"lineAlpha" => 0.4,
		"title" => "NOTICE",
		"valueField" => "NOTICE",
		"colorField" => "cNOTICE", 
		"fillColorsField" => "cNOTICE" ), array( "balloonText" => "WARNING",
		"fillAlphas" => 0.8,
		"lineAlpha" => 0.4,
		"title" => "WARNING",
		"valueField" => "WARNING",
		"colorField" => "cWARNING", 
		"fillColorsField" => "cWARNING" ), array( "balloonText" => "ERROR",
		"fillAlphas" => 0.8,
		"lineAlpha" => 0.4,
		"title" => "ERROR",
		"valueField" => "ERROR",
		"colorField" => "cERROR", 
		"fillColorsField" => "cERROR" ), array( "balloonText" => "CRITICAL",
		"fillAlphas" => 0.8,
		"lineAlpha" => 0.4,
		"title" => "CRITICAL",
		"valueField" => "CRITICAL",
		"colorField" => "cCRITICAL", 
		"fillColorsField" => "cCRITICAL" ), array( "balloonText" => "ALERT",
		"fillAlphas" => 0.8,
		"lineAlpha" => 0.4,
		"title" => "ALERT",
		"valueField" => "ALERT",
		"colorField" => "cALERT", 
		"fillColorsField" => "cALERT" ), array( "balloonText" => "EMERGENCY",
		"fillAlphas" => 0.8,
		"lineAlpha" => 0.4,
		"title" => "EMERGENCY",
		"valueField" => "EMERGENCY",
		"colorField" => "cEMERGENCY", 
		"fillColorsField" => "cEMERGENCY" ));
	$arr[ "plotAreaBorderAlpha" ] = 0;
	$arr[ "marginTop" ] = 10;
	$arr[ "marginLeft" ] = 0;
	$arr[ "marginBottom" ] = 0;
	//$arr[ "chartScrollbar" ] = array();
	$arr[ "chartCursor" ] = array( 'cursor' => 0 );
	$arr[ "categoryField" ] = "Date";
	$arr[ "categoryAxis" ] = array( 'startOnAxis' => true, 'axisColor' => '#DADADA', 'gridAlpha' => 0.07, 'guides' => array() );
	$arr[ "exportConfig" ] = array( 'menuTop' => '10px', 'menuRight' => '10px', 'menuItems' => array( array( 'icon' => '../../images/export.png', 'format' => 'png' ) ) );
	
	echo json_encode($arr);

?>