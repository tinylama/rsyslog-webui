<!DOCTYPE html>

<?php include 'config.php'; ?>

<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>bootstrap-rsyslog-ui</title>

    <!-- Bootstrap -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-table.min.css" rel="stylesheet">
    <link href="css/bootstrap-tooltip.css" rel="stylesheet">
    <link href="css/custom.css" rel="stylesheet">   

    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
      <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->
  </head>

    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>
    <!-- Include all compiled plugins (below), or include individual files as needed -->
    <script src="js/bootstrap.min.js"></script>
    <script src="js/bootstrap-table.min.js"></script>
	<script src="js/bootstrap-tooltip.js"></script>
  	<script type="text/javascript">
	
	$(function () {
		
		getSummary();
		
		$('[data-toggle="tooltip"]').tooltip({
			'placement': 'top',  
			'trigger': 'hover focus'
		});
		
		$('#table-style').tooltip({
			'show': true,
			'selector':'.myselector',
			'placement': 'left',
			'title': function(event){
				var $this=$(this);
				//var tds=$this.find('td');            
				return $this.text();
				//return "Testing";
			},        
		});
	 	 
        $('#info, #debug, #notice, #warning, #err, #cmdSearch').click(function () {
            var classes = 'table table-hover small-table table-striped';
			
			getSummary();
			
            $('#table-style').bootstrapTable('destroy')
			.bootstrapTable({
				classes: classes,
				url: 'json/events.php?warning=' + toInt($('#warning').prop('checked')) + '&info=' + toInt($('#info').prop('checked'))
				 + '&debug=' + toInt($('#debug').prop('checked')) + '&err=' + toInt($('#err').prop('checked'))
				 + '&notice=' + toInt($('#notice').prop('checked'))
			});
		});

		$("#cmdJson").on("click", function() {
			$.get("json/events.php",function(data,status){
				alert("Data: " + data + "\nStatus: " + status);
			});
		});
				
		function redrawData(bDebug, bInfo, bNotice, bWarning, bError)
		{
			$('#debug').prop('checked', bDebug);
			$('#info').prop('checked', bInfo);
			$('#notice').prop('checked', bNotice);
			$('#warning').prop('checked', bWarning);
			$('#err').prop('checked', bError);
			
			getSummary();
			
			var classes = 'table table-hover small-table table-striped';
			$('#table-style').bootstrapTable('destroy')
				.bootstrapTable({
					classes: classes,
					url: 'json/events.php?warning=' + toInt($('#warning').prop('checked')) + '&info=' + toInt($('#info').prop('checked'))
					 + '&debug=' + toInt($('#debug').prop('checked')) + '&err=' + toInt($('#err').prop('checked'))
					 + '&notice=' + toInt($('#notice').prop('checked'))
				});
		}
		
		$('#cmdReset').click(function () {
			redrawData(true, true, true, true, true);
		});

		$("#pgDebug").on("click", function() {
			redrawData(true, false, false, false, false);
		});

		$("#pgNotice").on("click", function() {
			redrawData(false, false, true, false, false);
		});

		$("#pgInfo").on("click", function() {
			redrawData(false, true, false, false, false);
		});

		$("#pgWarning").on("click", function() {
			redrawData(false, false, false, true, false);
		});

		$("#pgError").on("click", function() {
			redrawData(false, false, false, false, true);
		});
    });

	function getSummary() {
		$.getJSON( "json/events_summary.php", function( data ) {
			var items = [];
			
			$("#debugmessages").empty();

			/*
			$( "<div>", {
				"class": "my-new-list",
				html: JSON.stringify(data)
			}).appendTo( "#debugmessages" );
			*/
			
			var items = data.length;
			var sum = 0;
			
			for(var x = 0; x < items; x++)
			{
				switch(data[x][0])
				{
				/*
					case 0: { $("#pgDebug").attr("style", "width: " + data[x][1]/sum + "%"); }
					case 1: { $("#pgDebug").attr("style", "width: " + data[x][1]/sum + "%"); }
					case 2: { $("#pgDebug").attr("style", "width: " + data[x][1]/sum + "%"); }
				*/
					case "3": { if($("#err").prop('checked')) { sum = sum + data[x][1]; } break; }
					case "4": { if($("#warning").prop('checked')) { sum = sum + data[x][1]; } break; }
					case "5": { if($("#notice").prop('checked')) { sum = sum + data[x][1]; } break; }
					case "6": { if($("#info").prop('checked')) { sum = sum + data[x][1]; } break; }
					case "7": { if($("#debug").prop('checked')) { sum = sum + data[x][1]; } break; }
				}
			
			
				//sum = sum + data[x][1];
			}
			
			for(var x = 0; x < items; x++)
			{
				switch(data[x][0])
				{
				/*
					case 0: { $("#pgDebug").attr("style", "width: " + data[x][1]/sum + "%"); }
					case 1: { $("#pgDebug").attr("style", "width: " + data[x][1]/sum + "%"); }
					case 2: { $("#pgDebug").attr("style", "width: " + data[x][1]/sum + "%"); }
				*/
					case "3": { if($("#err").prop('checked')) { $("#pgError").css('width', ((data[x][1]/sum) * 100) + "%"); } else { $("#pgError").css('width', "0%"); } break; }
					case "4": { if($("#warning").prop('checked')) { $("#pgWarning").css('width', ((data[x][1]/sum) * 100) + "%"); } else { $("#pgWarning").css('width', "0%"); } break; }
					case "5": { if($("#notice").prop('checked')) { $("#pgNotice").css('width', ((data[x][1]/sum) * 100) + "%"); } else { $("#pgNotice").css('width', "0%"); } break; }
					case "6": { if($("#info").prop('checked')) { $("#pgInfo").css('width', ((data[x][1]/sum) * 100) + "%"); } else { $("#pgInfo").css('width', "0%"); } break; }
					case "7": { if($("#debug").prop('checked')) { $("#pgDebug").css('width', ((data[x][1]/sum) * 100) + "%"); } else { $("#pgDebug").css('width', "0%"); } break; }
				}
			}
		});
	}
	
	function toInt( val ) {
		return val & 1;
	}
	
	function rowStyle(row, index) {

		return {};
		var classes = ['active', 'success', 'info', 'warning', 'danger'];
		
        if (index % 2 === 0 && index / 2 < classes.length) {
            return {
                class: classes[index / 2]
            };
        } 
        return { classes: "warning" };
    }
	
	function SeverityFormat(value)
	{
		if(value == "0") return "<span class=\"label label-danger\">EMERGENCY</span>"; 
		if(value == "1") return "<span class=\"label label-danger\">ALERT</span>"; 
		if(value == "2") return "<span class=\"label label-danger\">CRITICAL</span>"; 
		if(value == "3") return "<span class=\"label label-danger\">ERROR</span>"; 
		if(value == "4") return "<span class=\"label label-warning\">WARNING</span>"; 
		if(value == "5") return "<span class=\"label label-success\">NOTICE</span>"; 
		if(value == "6") return "<span class=\"label label-info\">INFO</span>"; 
		if(value == "7") return "<span class=\"label label-primary\">DEBUG</span>"; 
		else return value;
	}
	
	function MessagetypeFormat(value)
	{
		return "SYSLOG";
	}
	
	function MessageFormat(value)
	{
		return "<a class='myselector' href='#' data-toggle='tooltip' data-title='" + value + "'>" + value + "</a>";
	}

	function FacilityFormat(value)
	{
		switch(value)
		{
			case "0": { return "KERNEL-MESSAGE"; break; }
			case "1": { return "USER-MESSAGE"; break; }
			case "2": { return "MAIL-SYSTEM"; break; }
			case "3": { return "SECURITY-DAEMON"; break; }
			case "4": { return "AUTH-MESSAGE"; break; }
			case "5": { return "SYSLOGD"; break; }
			case "6": { return "PRINTER"; break; }
			case "7": { return "NETWORK"; break; }
			case "8": { return "UUCP"; break; }
			case "9": { return "CRON"; break; }
			case "10": { return "AUTH-MESSAGE"; break; }
			case "11": { return "FTP"; break; }
			case "12": { return "NTP"; break; }
			case "13": { return "LOG-AUDIT"; break; }
			case "14": { return "LOG-ALERT"; break; }
			case "15": { return "CLOCK-DAEMON"; break; }
			case "16": { return "LOCAL0"; break; }
			case "17": { return "LOCAL1"; break; }
			case "18": { return "LOCAL2"; break; }
			case "19": { return "LOCAL3"; break; }
			case "20": { return "LOCAL4"; break; }
			case "21": { return "LOCAL5"; break; }
			case "22": { return "LOCAL6"; break; }
			case "23": { return "LOCAL7"; break; }
		}
		//if(value == "0") return "KERNEL"; 
		//else return value;
	}
	</script>
  
 <body>
 
<div style="width:80%; margin: 0 auto; display: block">
<nav class="navbar navbar-default" role="navigation">
  <div class="container-fluid">
    <!-- Brand and toggle get grouped for better mobile display -->
    <div class="navbar-header">
      <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1">
        <span class="sr-only">Toggle navigation</span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
      </button>
      <a class="navbar-brand" href="#">bootstrap-rsyslog-ui</a>
    </div>

    <!-- Collect the nav links, forms, and other content for toggling -->
    <div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
      <ul class="nav navbar-nav">
        <li id="cmdEvents" class="active events"><a href="#">Events <span class="sr-only">(current)</span></a></li>
        <li id="cmdReports" class="reports"><a href="reports.php">Reports</a></li>
      <!--  
		<li class="dropdown">
          <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Dropdown <span class="caret"></span></a>
          <ul class="dropdown-menu" role="menu">
            <li><a href="#">Action</a></li>
            <li><a href="#">Another action</a></li>
            <li><a href="#">Something else here</a></li>
            <li class="divider"></li>
            <li><a href="#">Separated link</a></li>
            <li class="divider"></li>
            <li><a href="#">One more separated link</a></li>
          </ul>
        </li>
      -->
	  </ul>
      <form class="navbar-form navbar-left" role="search">
        <div class="form-group">
          <input type="text" class="form-control input-widesearch" placeholder="Search">
        </div>
        <button id="cmdSearch" type="submit" class="btn btn-default">Search</button>
        <button id="cmdReset" type="submit" class="btn btn-default">Reset</button>
      </form>
	  <ul class="nav navbar-nav navbar-right">
		<!-- <li class=""><img src="images/HMS2.png" width="100px"/></li> -->
	  </ul>
    </div><!-- /.navbar-collapse -->
  </div><!-- /.container-fluid -->
</nav>

<div id="debugmessages"></div>

<div class="progress">
  <div id="pgDebug" class="progress-bar progress-bar-primary progress-bar-striped" style="width: 0%" data-toggle="tooltip" title="Debug">
    <span class="sr-only">20% Complete (debug)</span>
  </div>
  <div id="pgInfo" class="progress-bar progress-bar-info progress-bar-striped" style="width: 0%" data-toggle="tooltip" title="Information">
    <span class="sr-only">20% Complete (info)</span>
  </div>
  <div id="pgNotice" class="progress-bar progress-bar-success progress-bar-striped" style="width: 0%" data-toggle="tooltip" title="Notice">
    <span class="sr-only">20% Complete (notice)</span>
  </div>
  <div id="pgWarning" class="progress-bar progress-bar-warning progress-bar-striped" style="width: 0%" data-toggle="tooltip" title="Warning">
    <span class="sr-only">20% Complete (warning)</span>
  </div>
  <div id="pgError" class="progress-bar progress-bar-danger progress-bar-striped" style="width: 0%" data-toggle="tooltip" title="Error">
    <span class="sr-only">20% Complete (danger)</span>
  </div>
</div>

<div>
    <label><input id="debug" type="checkbox" checked=""><span class="label label-primary">DEBUG</span></label>
    <label><input id="info" type="checkbox" checked=""><span class="label label-info">INFO</span></label>
    <label><input id="notice" type="checkbox" checked=""><span class="label label-success">NOTICE</span></label>
    <label><input id="warning" type="checkbox" checked=""><span class="label label-warning">WARNING</span></label>
    <label><input id="err" type="checkbox" checked=""><span class="label label-danger">ERROR</span></label>
</div>

  <!-- Table class="table small-table" -->
  <table id="table-style" class="table small-table eventtable table-striped" data-toggle="table" data-url="json/events.php" data-cache="false" data-row-style="rowStyle">
	<thead> 
	<tr>
		<th data-field="Priority" data-formatter="SeverityFormat">Severity</th>
		<th data-field="DeviceReportedTime">Date</th>
		<th data-field="Priority" data-visible="false">HiddenSeverity</th>
		<th data-field="Facility" data-formatter="FacilityFormat">Facility</th>
		<th data-field="FromHost">Host</th>
		<th data-field="SysLogTag">Syslogtags</th>
		<th data-field="processid" data-visible="false">ProcessID</th>
		<th data-field="Messagetype" data-formatter="MessagetypeFormat">Messagetype</th>
		<th data-field="SmallMessage" data-toggle="tooltip" data-content="Message" data-formatter="MessageFormat">Message</th>
		<th data-field="Message" data-visible="false">Message</th>
	</tr>
	</thead>
	
  </table>

</div>
<footer class="footer">
    <div class="container">
	</div>
</footer>
  </body>
</html>

