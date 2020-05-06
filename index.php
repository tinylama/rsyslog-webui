<!DOCTYPE html>

<?php include 'config.php'; ?>

<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $site_name; ?></title>

    <!-- Bootstrap -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-table.min.css" rel="stylesheet">
    <link href="css/bootstrap-tooltip.css" rel="stylesheet">
	<link href="css/bootstrap-context.css" rel="stylesheet"> 
    <link href="css/custom.css" rel="stylesheet">   

    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
      <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->
	<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
	<link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
	<link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
	<link rel="manifest" href="site.webmanifest">
  </head>

    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>
    <!-- Include all compiled plugins (below), or include individual files as needed -->
    <script src="js/bootstrap.min.js"></script>
    <script src="js/bootstrap-table.min.js"></script>
	<script src="js/bootstrap-tooltip.js"></script>
	<script src="js/bootstrap-context.js"></script> 

  	<script type="text/javascript">
	
	$(function () {
		
		var firstRowID, lastRowID;
		
		getSummary();
		
		var selectedRow = "";
		var selectedNodeText = "";

		context.init({
			fadeSpeed: 100,
			filter: function ($obj){},
			above: 'auto',
			preventDoubleContext: true,
			compress: false
		});

		var menu = "", menudate = "";
		
		$('#table-style').css( 'cursor', 'pointer' );

		$("#table-style").delegate("tr td", "mousedown", function(event) {
			if(event.which == 3){
				
				context.destroy();
				//context.destroy($("table-style tr"));

				var selectedRow = $(this);
				selectedNodeText = selectedRow.html();
				selectedColumn = "";
				
				if(selectedRow.find('span').length > 0) selectedNodeText = selectedRow.find('span').html();

				if( selectedRow.index() == 6 ) return;
				if( selectedRow.index() == 0 && selectedRow.hasClass('expandedMessage') == false ) selectedColumn = "Severity";
				if( selectedRow.index() == 1 ) 
				{
					selectedNodeText = selectedNodeText.replace( " ", "T" );
					selectedColumn = "Date";
				}
				if( selectedRow.index() == 2 ) selectedColumn = "Facility";
				if( selectedRow.index() == 3 ) selectedColumn = "Host";
				if( selectedRow.index() == 4 ) selectedColumn = "Syslogtag";
				if( selectedRow.index() == 5 ) return;
				if( selectedRow.hasClass('expandedMessage') == true ) return;
				
				menudate = [{
				text: 'Add logs newer than \'' + selectedNodeText + '\' to filterset',
				action: function () {
						$("#txtSearch").val($("#txtSearch").val() + "\"" + selectedColumn + "\">\"" + selectedNodeText + "\" ");
						$('#cmdSearch').click();
						context.destroy();
					}
				}, {
					text: 'Add logs older than \'' + selectedNodeText + '\' in filterset',
					action: function (t) {
						$("#txtSearch").val($("#txtSearch").val() + "\"" + selectedColumn + "\"<\"" + selectedNodeText + "\" ");
						$('#cmdSearch').click();
						context.destroy();
					}
				}];
				
				menu = [{
				text: 'Add \'' + selectedNodeText + '\' to filterset',
				action: function () {
						$("#txtSearch").val($("#txtSearch").val() + "\"" + selectedColumn + "\"=\"" + selectedNodeText + "\" ");
						$('#cmdSearch').click();
						context.destroy();
					}
				}, {
					text: 'Exclude \'' + selectedNodeText + '\' in filterset',
					action: function (t) {
						$("#txtSearch").val($("#txtSearch").val() + "\"" + selectedColumn + "\"<>\"" + selectedNodeText + "\" ");
						$('#cmdSearch').click();
						context.destroy();
					}
				}];
				
				if( selectedRow.index() == 1 ) 
					context.attach($("table-style tr"), menudate);
				else
					context.attach($("table-style tr"), menu);
			}
		}); 
	
		$('#table-style').on('click-row.bs.table', function (e, row, $element) {
			//console.log( JSON.stringify( row ) );
			
			if( $element.hasClass('expandedMessage') == false)
			{
				// Add new tr with full message + add class
				$element.after('<tr><td colspan="7" class="expandedMessage"><div class="increase-font-size">' + escapeHtml(row.Message) + '</div></td></tr>');
				$element.addClass('expandedMessage');
			}
			else
			{
				// Remove previous created tr + remove class
				$element.closest('tr').next().remove();
				$element.removeClass('expandedMessage');
			}
		});

		$('[data-toggle="tooltip"]').tooltip({
			'placement': 'top',  
			'trigger': 'hover focus'
		});

		$('[data-toggle="tooltip-bottom"]').tooltip({
			'placement': 'bottom',  
			'trigger': 'hover focus'
		});
		
		$('#cmdSearch').click(function(e) {
			var classes = 'table table-hover small-table table-striped';
			e.preventDefault();
			var search = $('#txtSearch').val();
			//$('#txtSearch').val(search);
			
			getSummary();

			$('#table-style').bootstrapTable('destroy')
				.bootstrapTable({
					classes: classes,
					url: 'json/events.php?&search=' + encodeURIComponent(search)
			});
			
			console.log(encodeURIComponent(search));
		});
		
		$('#cmdReset').click(function (e) {
			e.preventDefault();
			$("#txtSearch").val("");
			$('#cmdSearch').click();
		});

		$("#pgDebug").on("click", function() {
			$("#txtSearch").val($("#txtSearch").val() + "\"Severity\"=\"DEBUG\" ");
			$('#cmdSearch').click();
		});

		$("#pgNotice").on("click", function() {
			$("#txtSearch").val($("#txtSearch").val() + "\"Severity\"=\"NOTICE\" ");
			$('#cmdSearch').click();
		});

		$("#pgInfo").on("click", function() {
			$("#txtSearch").val($("#txtSearch").val() + "\"Severity\"=\"INFO\" ");
			$('#cmdSearch').click();
		});

		$("#pgWarning").on("click", function() {
			$("#txtSearch").val($("#txtSearch").val() + "\"Severity\"=\"WARNING\" ");
			$('#cmdSearch').click();
		});

		$("#pgError").on("click", function() {
			$("#txtSearch").val($("#txtSearch").val() + "\"Severity\"=\"ERROR\" ");
			$('#cmdSearch').click();
		});
		
    });

	function getSummary() {
		$.getJSON( "json/events_summary.php?" + "search=" + encodeURIComponent($("#txtSearch").val()), function( data ) {
			var items = [];
			
			var items = data.length;
			var sum = 0;
			
			for(var x = 0; x < items; x++)
			{
				switch(data[x][0])
				{
					case "3": { sum = sum + data[x][1]; break; }
					case "4": { sum = sum + data[x][1]; break; }
					case "5": { sum = sum + data[x][1]; break; }
					case "6": { sum = sum + data[x][1]; break; }
					case "7": { sum = sum + data[x][1]; break; }
				}
			}
			
			var three = false;
			var four = false;
			var five = false;
			var six = false;
			var seven = false;
			
			for(var x = 0; x < items; x++)
			{
				switch(data[x][0])
				{
					case "3": { $("#pgError").css('width', ((data[x][1]/sum) * 100) + "%"); three = true; break; }
					case "4": { $("#pgWarning").css('width', ((data[x][1]/sum) * 100) + "%"); four = true; break; }
					case "5": { $("#pgNotice").css('width', ((data[x][1]/sum) * 100) + "%"); five = true; break; }
					case "6": { $("#pgInfo").css('width', ((data[x][1]/sum) * 100) + "%"); six = true; break; }
					case "7": { $("#pgDebug").css('width', ((data[x][1]/sum) * 100) + "%"); seven = true; break; }
				}
			}
			
			if( three == false ) { $("#pgError").css('width', "0%"); }
			if( four == false ) { $("#pgWarning").css('width', "0%"); }
			if( five == false ) { $("#pgNotice").css('width', "0%"); }
			if( six == false ) { $("#pgInfo").css('width', "0%"); }
			if( seven == false ) { $("#pgDebug").css('width', "0%"); }
			
		});
	}
	
	function toInt( val ) {
		return val & 1;
	}
	
	function rowStyle(row, index) {
		return {
			classes: 'ID_' + row.ID
		};
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
		return escapeHtml(value);
	}
	
	function idFormat(value, row)
	{
		console.log( row + ": " + value );
		return value;
	}

	function LargeMessageFormat(value)
	{
		return "<span class='largemessage'>" + value + "</span>";
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
			case "10": { return "AUTH-MESSAGE-10"; break; }
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
	}
	
        function escapeHtml(text) {
                return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

	</script>
  
 <body>
 
<div style="width:90%; margin: 0 auto; display: block">
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
		<a class="navbar-brand" href="#"><?php echo $site_name; ?></a>
    </div>

    <!-- Collect the nav links, forms, and other content for toggling -->
    <div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
      <ul class="nav navbar-nav">
        <li id="cmdEvents" class="active events" data-toggle="tooltip-bottom" title="Events"><a href="#"><span class="glyphicon glyphicon-home" aria-hidden="true"></span></a></li>
	  </ul>
      <form class="navbar-form navbar-right" role="search">
        <div class="form-group">
          <input id="txtSearch" type="text" class="form-control input-widesearch" placeholder="Search" style="width: 500px">
        </div>
        <button id="cmdSearch" type="submit" class="btn btn-default" data-toggle="tooltip-bottom" title="Refresh"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span></button>
        <button id="cmdReset" type="submit" class="btn btn-default" data-toggle="tooltip-bottom" title="Reset all">Reset</button>
      </form>
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

<!-- Modal -->
<div class="modal" id="mdEventDetails" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span class="sr-only">Close</span></button>
        <h4 class="modal-title" id="mdEventDetailsLabel">Modal title</h4>
      </div>
      <div class="modal-body">
        ...
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

  <!-- Table class="table small-table" -->
  <table id="table-style" class="table small-table table-striped" data-toggle="table" data-url="json/events.php" data-height="800" data-pagination="true" data-page-size="100">
	<thead> 
		<tr>
			<th data-field="ID" data-visible="false" data-formatter="idFormat">Id</th>
			<th data-field="Priority" data-formatter="SeverityFormat">Severity</th>
			<th data-field="DeviceReportedTime">Date</th>
			<th data-field="Priority" data-visible="false">HiddenSeverity</th>
			<th data-field="Facility" data-formatter="FacilityFormat">Facility</th>
			<th data-field="FromHost">Host</th>
			<th data-field="SysLogTag">Syslogtag</th>
			<th data-field="processid" data-visible="false">ProcessID</th>
			<th data-field="Messagetype" data-formatter="MessagetypeFormat">Messagetype</th>
			<th data-field="SmallMessage" data-toggle="tooltip" data-content="Message" data-formatter="MessageFormat">Message</th>
			<th data-field="Message" data-visible="false" data-formatter="LargeMessageFormat">Message</th>
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

