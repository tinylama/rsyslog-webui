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
    <script language="javascript" type="text/javascript" src="chart-api/flot/jquery.flot.min.js"></script>
    <script language="javascript" type="text/javascript" src="chart-api/flot/jquery.flot.categories.min.js"></script>
	<script type="text/javascript" src="chart-api/amcharts/amcharts/amcharts.js"></script>
	<script type="text/javascript" src="chart-api/amcharts/amcharts/pie.js"></script>
	<script type="text/javascript" src="chart-api/amcharts/amcharts/serial.js"></script>
  	<script type="text/javascript">
	$(function () {	
		
		ShowAmchartSeverityOverview();
		
	});
	
	function ShowAmchartSeverityOverview() {
		
		var data = [];
		
		function onDataReceivedSeverityOverview(series) {
			var chart = AmCharts.makeChart("chartdiv", series);
		}

		function onDataReceivedSeverityOverviewPie(series) {
			var chart = AmCharts.makeChart("chartdiv2", series);
		}

		function onDataReceivedSeverityDaysOverview(series) {
			var chart = AmCharts.makeChart("chartdiv_days", series);
		}

		$.ajax({
			url: "json/reports/report_amchart_severity_overview.php",
			type: "GET",
			dataType: "json",
			success: onDataReceivedSeverityOverview
		});

		$.ajax({
			url: "json/reports/report_amchart_severity_overview_pie.php",
			type: "GET",
			dataType: "json",
			success: onDataReceivedSeverityOverviewPie
		});
		
		$.ajax({
			url: "json/reports/report_amchart_severity_days_overview.php",
			type: "GET",
			dataType: "json",
			success: onDataReceivedSeverityDaysOverview
		});
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
			<li id="cmdEvents" class="events"><a href="index.php">Events <span class="sr-only">(current)</span></a></li>
			<li id="cmdReports" class="active reports"><a href="#">Reports</a></li>
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
		</div><!-- /.navbar-collapse -->
	  </div><!-- /.container-fluid -->
	</nav>
		
	<div id="debug"></div>
	<div id="chartdiv2" class="chartdiv" style="height:400px;"></div>
	<div id="chartdiv_days" class="chartdiv_small"></div>
	<div id="chartdiv" class="chartdiv_small"></div>

</div>
<footer class="footer">
    <div class="container">
	</div>
</footer>
  </body>
</html>
