<?php
	include '../config.php';
	
	$wherestring = "";

	if(isset($_GET["search"])){
		$searchstring = $_GET["search"];
	}else{
		$searchstring = "";
	}
	$query = "SELECT Priority, COUNT(ID) AS Qty FROM ";
	$qArray = array();

	if( $searchstring != "" )
	{
		$wherestring = "";
		$urlencoded = trim(urldecode($searchstring));
		$array = explode( " ", $urlencoded );
		
		for( $x = 0; $x < count($array); $x++ )
		{

			$keysarray = str_split( trim($array[$x]) );
			$keyname = "";
			$keyvalue = "";
			$expression = "";
			$position = 0;
						
			for( $y = 0; $y < count( $keysarray ); $y++ )
			{
				if( $position == 3 && $keysarray[$y] == "\"" ) { $position = 4; }
				if( $position == 3 && $keysarray[$y] != "\"" ) { $keyvalue .= $keysarray[$y]; }
				if( $position == 2 && $keysarray[$y] == "\"" ) { $position = 3; }
				if( $position == 2 && $keysarray[$y] != "\"" ) { $expression .= $keysarray[$y]; }
				if( $position == 1 && $keysarray[$y] == "\"" ) { $position = 2; }
				if( $position == 1 && $keysarray[$y] != "\"" ) { $keyname .= $keysarray[$y]; }
				if( $position == 0 && $keysarray[$y] == "\"" ) { $position = 1; }
			}	

			if( $keyname == "Host" ) $keyname = "FromHost";
			if( $keyname == "Facility" ) 
			{
				$keyname = "Facility";
				
				if( $keyvalue == "KERNEL-MESSAGE" ) { $keyvalue = "0"; }
				if( $keyvalue == "USER-MESSAGE" ) { $keyvalue = "1"; }
				if( $keyvalue == "MAIL-SYSTEM" ) { $keyvalue = "2"; }
				if( $keyvalue == "SECURITY-DAEMON" ) { $keyvalue = "3"; }
				if( $keyvalue == "AUTH-MESSAGE" ) { $keyvalue = "4"; }
				if( $keyvalue == "SYSLOGD" ) { $keyvalue = "5"; }
				if( $keyvalue == "PRINTER" ) { $keyvalue = "6"; }
				if( $keyvalue == "NETWORK" ) { $keyvalue = "7"; }
				if( $keyvalue == "UUCP" ) { $keyvalue = "8"; }
				if( $keyvalue == "CRON" ) { $keyvalue = "9"; }
				if( $keyvalue == "AUTH-MESSAGE" ) { $keyvalue = "10"; }
				if( $keyvalue == "FTP" ) { $keyvalue = "11"; }
				if( $keyvalue == "NTP" ) { $keyvalue = "12"; }
				if( $keyvalue == "LOG-AUDIT" ) { $keyvalue = "13"; }
				if( $keyvalue == "LOG-ALERT" ) { $keyvalue = "14"; }
				if( $keyvalue == "CLOCK-DAEMON" ) { $keyvalue = "15"; }
				if( $keyvalue == "LOCAL0" ) { $keyvalue = "16"; }
				if( $keyvalue == "LOCAL1" ) { $keyvalue = "17"; }
				if( $keyvalue == "LOCAL2" ) { $keyvalue = "18"; }
				if( $keyvalue == "LOCAL3" ) { $keyvalue = "19"; }
				if( $keyvalue == "LOCAL4" ) { $keyvalue = "20"; }
				if( $keyvalue == "LOCAL5" ) { $keyvalue = "21"; }
				if( $keyvalue == "LOCAL6" ) { $keyvalue = "22"; }
				if( $keyvalue == "LOCAL7" ) { $keyvalue = "23"; }
			}
			if( $keyname == "Date" ) $keyname = "DeviceReportedTime";
			if( $keyname == "Severity" ) 
			{
				$keyname = "Priority";
				$keyvalue = strtoupper( $keyvalue );
				
				if( $keyvalue == "EMERGENCY" ) $keyvalue = "0";
				if( $keyvalue == "ALERT" ) $keyvalue = "1";
				if( $keyvalue == "CRITICAL" ) $keyvalue = "2";
				if( $keyvalue == "ERROR" ) $keyvalue = "3";
				if( $keyvalue == "WARNING" ) $keyvalue = "4";
				if( $keyvalue == "NOTICE" ) $keyvalue = "5";
				if( $keyvalue == "INFO" ) $keyvalue = "6";
				if( $keyvalue == "DEBUG" ) $keyvalue = "7";
			}
			if( $keyname == "Syslogtag" ) $keyname = "SysLogTag";
			if( $keyname == "Messagetype" ) $keyname = "Messagetype";
			
			if( $expression != "=" && $expression != "<>" && $expression != "<" && $expression != ">" )
				exit();
			
			if( $expression == "=" ) $expression = "LIKE";
			
			$qArray[ "param".strval( $x ) ] = $keyvalue; 
			
			if( $wherestring == "" )
				$wherestring = " WHERE $keyname $expression :param".strval( $x )." ";
			else
				$wherestring .= " AND $keyname $expression :param".strval( $x )." ";
		}
	}

	$items = array();

	$db = new PDO( "mysql:host=$mysql_server;dbname=$mysql_database;charset=utf8", $mysql_user, $mysql_password );

	$table_query = "(SELECT ID, Priority FROM SystemEvents " . $wherestring . " ORDER BY ID DESC LIMIT 2000) as tmp ";
	
	$rows = array();
	$stmt = $db->prepare($query . $table_query . " GROUP BY Priority");

	if( $stmt->execute( $qArray ) )
	foreach( $stmt as $row ) {
	
		$prio = "";
		
		if( $row["Priority"] == "0" ) $prio = "EMERGENCY"; 
		if( $row["Priority"] == "1" ) $prio = "ALERT"; 
		if( $row["Priority"] == "2" ) $prio = "CRITICAL"; 
		if( $row["Priority"] == "3" ) $prio = "ERROR"; 
		if( $row["Priority"] == "4" ) $prio = "WARNING"; 
		if( $row["Priority"] == "5" ) $prio = "NOTICE"; 
		if( $row["Priority"] == "6" ) $prio = "INFO"; 
		if( $row["Priority"] == "7" ) $prio = "DEBUG"; 

		array_push($items, array($row["Priority"], intval($row["Qty"])));
	}

	echo json_encode($items);

?>
