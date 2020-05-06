<!DOCTYPE html>
<?php include 'config.php'; ?>
<html lang="en">
    
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>
            <?php echo $site_name; ?>
        </title>
        <!--  jQuery and Popper.js -->
		<script src="https://code.jquery.com/jquery-3.4.1.slim.min.js" integrity="sha384-J6qa4849blE2+poT4WnyKhv5vZF5SrPo0iEjwBvKU7imGFAV0wwj1yYfoRSJoZ+n" crossorigin="anonymous"></script>
		<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
		<!-- Bootstrap -->
		<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css" integrity="sha384-Vkoo8x4CGsO3+Hhxv8T/Q5PaXtkKtu6ug5TOeNV6gBiFeWPGFN9MuhOf23Q9Ifjh" crossorigin="anonymous">
		<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>
        <!-- rsyslog-webui css -->
		<link href="css/custom.css" rel="stylesheet">
        <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media
        queries -->
        <!-- WARNING: Respond.js doesn't work if you view the page via file://
        -->
        <!--[if lt IE 9]>
        <![endif]-->
    </head>
    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <!-- Include all compiled plugins (below), or include individual files
    as needed -->
    
    <body>
        <div style="width:90%; margin: 0 auto; display: block">
            <nav class="navbar navbar-light bg-light navbar-expand-md" role="navigation">
                <!-- Brand and toggle get grouped for better mobile display -->
                <button type="button" class="navbar-toggler collapsed" data-toggle="collapse"
                data-target="#bs-example-navbar-collapse-1">	<span class="sr-only">Toggle navigation</span>
&#x2630;</button>	<a class="navbar-brand"
                href="#"><?php echo $site_name; ?></a>
                <!-- Collect the nav links, forms,
                and other content for toggling -->
                <div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
                    <ul class="nav navbar-nav">
                        <li id="cmdEvents" class="active events nav-item" data-toggle="tooltip-bottom"
                        title="Events"><a href="#" class="nav-link"><span class="glyphicon glyphicon-home" aria-hidden="true"></span></a>
                        </li>
                    </ul>
                    <form class="form-inline " role="search">
                        <div class="form-group">
                            <input id="txtSearch" type="text" class="form-control input-widesearch"
                            placeholder="Search" style="width: 500px">
                        </div>
                        <button id="cmdSearch" type="submit" class="btn btn-secondary" data-toggle="tooltip-bottom"
                        title="Refresh"><span class="glyphicon glyphicon-refresh" aria-hidden="true"></span>
                        </button>
                        <button id="cmdReset" type="submit" class="btn btn-secondary" data-toggle="tooltip-bottom"
                        title="Reset all">Reset</button>
                    </form>
                </div>
                <!-- /.navbar-collapse -->
                <!-- /.container-fluid -->
            </nav>
            <div id="debugmessages"></div>
            <div class="progress">
                <div id="pgDebug" class="progress-bar progress-bar-primary progress-bar-striped"
                style="width: 0%" data-toggle="tooltip" title="Debug"> <span class="sr-only">20% Complete (debug)</span>
                </div>
                <div id="pgInfo" class="progress-bar progress-bar-info progress-bar-striped"
                style="width: 0%" data-toggle="tooltip" title="Information"> <span class="sr-only">20% Complete (info)</span>
                </div>
                <div id="pgNotice" class="progress-bar progress-bar-success progress-bar-striped"
                style="width: 0%" data-toggle="tooltip" title="Notice"> <span class="sr-only">20% Complete (notice)</span>
                </div>
                <div id="pgWarning" class="progress-bar progress-bar-warning progress-bar-striped"
                style="width: 0%" data-toggle="tooltip" title="Warning"> <span class="sr-only">20% Complete (warning)</span>
                </div>
                <div id="pgError" class="progress-bar progress-bar-danger progress-bar-striped"
                style="width: 0%" data-toggle="tooltip" title="Error"> <span class="sr-only">20% Complete (danger)</span>
                </div>
            </div>
            <!-- Modal -->
            <div class="modal" id="mdEventDetails" tabindex="-1" role="dialog" aria-labelledby="myModalLabel"
            aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&#xD7;</span><span class="sr-only">Close</span>
                            </button>
                             <h4 class="modal-title" id="mdEventDetailsLabel">Modal title</h4>
                        </div>
                        <div class="modal-body">...</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" data-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Table class="table small-table" -->
            <table id="table-style" class="table small-table table-striped" data-toggle="table"
            data-url="json/events.php" data-height="800" data-pagination="true" data-page-size="100">
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
                        <th data-field="SmallMessage" data-toggle="tooltip" data-content="Message"
                        data-formatter="MessageFormat">Message</th>
                        <th data-field="Message" data-visible="false" data-formatter="LargeMessageFormat">Message</th>
                    </tr>
                </thead>
            </table>
        </div>
        <footer class="footer">
            <div class="container"></div>
        </footer>
    </body>

</html>