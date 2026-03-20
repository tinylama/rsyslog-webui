<?php
require_once __DIR__ . '/auth.php';

if (auth_is_enabled()) {
	auth_bootstrap_once();
	if (empty($_SESSION['user_id'])) {
		require_once __DIR__ . '/includes/partials/landing.php';
		exit;
	}
}

require_login();
$currentUser = auth_current_user();
$isAdmin = is_array($currentUser) && (($currentUser['role'] ?? '') === 'admin');
$displayName = is_array($currentUser) ? (string) ($currentUser['display_name'] ?? '') : '';
$auth_enabled = auth_is_enabled();
$devMode = auth_is_running_in_docker_dev();

$showOnboarding = false;
$onboardingCsrf = '';
if (is_array($currentUser) && isset($currentUser['id'])) {
	$uid = (int) $currentUser['id'];
	$pdo = auth_db();
	$completed = auth_user_has_completed_onboarding($pdo, $uid);
	// Show the demo/tutorial overlay only in dev/docker environments.
	$showOnboarding = auth_is_running_in_docker_dev() && !$completed;
}

if ($showOnboarding) {
	if (!isset($_SESSION['csrf_onboarding']) || !is_string($_SESSION['csrf_onboarding']) || strlen($_SESSION['csrf_onboarding']) < 32) {
		$_SESSION['csrf_onboarding'] = bin2hex(random_bytes(32));
	}
	$onboardingCsrf = (string) $_SESSION['csrf_onboarding'];
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $site_name; ?></title>

    <!-- Bootstrap 5 (CDN) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-table@1.22.1/dist/bootstrap-table.min.css" rel="stylesheet">
	<link href="css/bootstrap-context.css" rel="stylesheet">
    <link href="css/custom.css" rel="stylesheet">
    <link href="css/layout.css" rel="stylesheet">
    <link href="css/dark-mode.css" rel="stylesheet">

    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
      <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->
	<link rel="manifest" href="site.webmanifest">
  </head>

    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>
    <!-- Bootstrap 5 JS (no jQuery dependency) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-table@1.22.1/dist/bootstrap-table.min.js"></script>
	<script src="js/bootstrap-context.js"></script>
	<script src="js/dark-mode-switch.js"></script>

  	<script type="text/javascript">
	
	$(function () {
		
		var firstRowID, lastRowID;
		
		getSummary();

		// Dev-only demo query buttons (non-blocking; keeps the normal search behavior).
		$(document).on('click', '.app-demoQuery', function () {
			var q = $(this).data('demoQuery');
			if (q === undefined || q === null) return;
			q = String(q);
			if (q === '') return;
			$('#txtSearch').val(q);
			if (typeof refreshEventsTable === 'function') {
				refreshEventsTable();
			} else {
				$('#txtSearch').trigger('input');
			}
		});

		try {
			$('#table-style').bootstrapTable('refreshOptions', {
				formatNoMatches: function () {
					return 'No events match your search or filters.';
				}
			});
		} catch (e) {}
		
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

				if( selectedRow.hasClass('expandedMessage') == true ) return;

				var colIdx = selectedRow.index();
				var rawField = $('#table-style thead tr').last().find('th').eq(colIdx).data('field');
				var fieldMap = { Priority: 'Severity', DeviceReportedTime: 'Date', Facility: 'Facility', FromHost: 'Host', SysLogTag: 'Syslogtag' };
				if (!rawField || !fieldMap[rawField]) return;
				selectedColumn = fieldMap[rawField];
				
				menudate = [{
				text: 'Add logs newer than \'' + selectedNodeText + '\' to filterset',
				action: function () {
						$("#txtSearch").val($("#txtSearch").val() + "\"" + selectedColumn + "\">\"" + selectedNodeText + "\" ");
						scheduleRefreshEventsTable();
						context.destroy();
					}
				}, {
					text: 'Add logs older than \'' + selectedNodeText + '\' in filterset',
					action: function (t) {
						$("#txtSearch").val($("#txtSearch").val() + "\"" + selectedColumn + "\"<\"" + selectedNodeText + "\" ");
						scheduleRefreshEventsTable();
						context.destroy();
					}
				}];
				
				menu = [{
				text: 'Add \'' + selectedNodeText + '\' to filterset',
				action: function () {
						$("#txtSearch").val($("#txtSearch").val() + "\"" + selectedColumn + "\"=\"" + selectedNodeText + "\" ");
						scheduleRefreshEventsTable();
						context.destroy();
					}
				}, {
					text: 'Exclude \'' + selectedNodeText + '\' in filterset',
					action: function (t) {
						$("#txtSearch").val($("#txtSearch").val() + "\"" + selectedColumn + "\"<>\"" + selectedNodeText + "\" ");
						scheduleRefreshEventsTable();
						context.destroy();
					}
				}];
				
				if (rawField === 'DeviceReportedTime') {
					selectedNodeText = selectedNodeText.replace(' ', 'T');
					context.attach($('#table-style tr'), menudate);
				} else {
					context.attach($('#table-style tr'), menu);
				}
			}
		}); 
	
		$('#table-style').on('click-row.bs.table', function (e, row, $element) {
			//console.log( JSON.stringify( row ) );
			
			if( $element.hasClass('expandedMessage') == false)
			{
				// Add new tr with full message + add class
				$element.after('<tr><td colspan="' + $('#table-style thead tr').last().find('th').length + '" class="expandedMessage"><div class="increase-font-size">' + escapeHtml(row.Message) + '</div></td></tr>');
				$element.addClass('expandedMessage');
			}
			else
			{
				// Remove previous created tr + remove class
				$element.closest('tr').next().remove();
				$element.removeClass('expandedMessage');
			}
		});

		// Tooltip initialization (Bootstrap 5 compatible) is intentionally omitted here:
		// the UI does not require tooltips for core functionality, and Bootstrap 5
		// tooltips are not jQuery-based.

		var searchDebounceTimer = null;
		var SEARCH_DEBOUNCE_MS = 320;

		function refreshEventsTable() {
			var classes = 'table table-sm table-hover small-table table-striped events-log-table';
			var search = $('#txtSearch').val();
			var hasFilterset = search.indexOf('"') !== -1;
			var urlParams = hasFilterset
				? "search=" + encodeURIComponent(search)
				: "q=" + encodeURIComponent(search);
			getSummary();
			$('#table-style').bootstrapTable('destroy')
				.bootstrapTable({
					classes: classes,
					url: 'json/events.php?' + urlParams,
					sidePagination: 'client',
					sortable: true
				});
		}

		function scheduleRefreshEventsTable() {
			if (searchDebounceTimer !== null) {
				clearTimeout(searchDebounceTimer);
			}
			searchDebounceTimer = setTimeout(function() {
				searchDebounceTimer = null;
				refreshEventsTable();
			}, SEARCH_DEBOUNCE_MS);
		}

		$('#txtSearch').on('input', function() {
			scheduleRefreshEventsTable();
		});

		$('form[role="search"]').on('submit', function(e) {
			e.preventDefault();
			if (searchDebounceTimer !== null) {
				clearTimeout(searchDebounceTimer);
				searchDebounceTimer = null;
			}
			refreshEventsTable();
		});

		$('#cmdSearch').click(function(e) {
			e.preventDefault();
			if (searchDebounceTimer !== null) {
				clearTimeout(searchDebounceTimer);
				searchDebounceTimer = null;
			}
			refreshEventsTable();
		});
		
		$('#cmdReset').click(function (e) {
			e.preventDefault();
			$("#txtSearch").val("");
			if (searchDebounceTimer !== null) {
				clearTimeout(searchDebounceTimer);
				searchDebounceTimer = null;
			}
			refreshEventsTable();
		});

		/**
		 * Severity strip click should behave like a "set" not an "append".
		 * The filter builder joins tokens with AND, so appending another
		 * `"Severity"="ERROR"` can create redundant filters and break the UX.
		 */
		function setSeverityFilter(sev) {
			var token = "\"Severity\"=\"" + sev + "\"";
			var current = ($('#txtSearch').val() || '').trim();
			var tokens = current === '' ? [] : current.split(/\s+/).filter(Boolean);
			var severityRe = /^"Severity"="(DEBUG|NOTICE|INFO|WARNING|ERROR)"$/;

			// Remove all severity tokens, then add back exactly one.
			tokens = tokens.filter(function (t) { return !severityRe.test(t); });
			tokens.push(token);

			// Keep trailing space so the filter builder's space-splitting is stable.
			$('#txtSearch').val(tokens.join(' ') + (tokens.length ? ' ' : ''));
			refreshEventsTable();
		}

		$("#pgDebug").on("click", function() {
			setSeverityFilter('DEBUG');
		});

		$("#pgNotice").on("click", function() {
			setSeverityFilter('NOTICE');
		});

		$("#pgInfo").on("click", function() {
			setSeverityFilter('INFO');
		});

		$("#pgWarning").on("click", function() {
			setSeverityFilter('WARNING');
		});

		$("#pgError").on("click", function() {
			setSeverityFilter('ERROR');
		});

		// Live / Tail mode:
		// Previously we refreshed by destroying & recreating the entire table every 5s.
		// That was expensive and looked like a hard refresh.
		// Now we fetch only new rows via `since_id` and merge into the existing dataset.
		var liveTimer = null;
		var liveFetchInFlight = false;
		var liveLimit = 200;
		function setLivePolling(on) {
			if (on) {
				if (liveTimer === null) {
					refreshEventsTable();
					liveTimer = setInterval(function() {
						if (liveFetchInFlight) return;
						liveFetchInFlight = true;

						try {
							var $table = $('#table-style');
							var tableData = $table.bootstrapTable('getData') || [];

							// ID desc ordering => first row should be newest, but we compute max defensively.
							var latestId = 0;
							for (var i = 0; i < tableData.length; i++) {
								var idv = parseInt(tableData[i].ID, 10);
								if (idv > latestId) latestId = idv;
							}

							// If the table has no data yet, fall back to a full refresh.
							if (latestId <= 0) {
								refreshEventsTable();
								liveFetchInFlight = false;
								return;
							}

							var search = $('#txtSearch').val();
							var hasFilterset = search.indexOf('"') !== -1;
							var urlParams = hasFilterset
								? "search=" + encodeURIComponent(search)
								: "q=" + encodeURIComponent(search);

							var url = 'json/events.php?' + urlParams + '&since_id=' + encodeURIComponent(String(latestId)) + '&limit=' + encodeURIComponent(String(liveLimit));

							$.getJSON(url, function(newRows) {
								if (!Array.isArray(newRows) || newRows.length === 0) {
									return;
								}

								// Merge new rows on top (newer = higher ID) then cap at 2000.
								var merged = newRows.concat(tableData);
								if (merged.length > 2000) {
									merged = merged.slice(0, 2000);
								}

								$table.bootstrapTable('load', merged);
								getSummary();
							}).fail(function() {
								// Soft-fail: if incremental fetch fails, don't break the UI.
							}).always(function() {
								liveFetchInFlight = false;
							});
						} catch (e) {
							liveFetchInFlight = false;
						}
					}, 5000);
				}
			} else if (liveTimer !== null) {
				clearInterval(liveTimer);
				liveTimer = null;
			}
		}
		$("#chkLive").on("change", function() {
			setLivePolling(this.checked);
		});
		setLivePolling($("#chkLive").prop("checked"));
		
    });

	function getSummary() { 
	    var search = $("#txtSearch").val();
	    var hasFilterset = search.indexOf('"') !== -1;
	    var urlParams = hasFilterset
	    	? "search=" + encodeURIComponent(search)
	    	: "q=" + encodeURIComponent(search);

	    $.getJSON("json/events_summary.php?" + urlParams, function(data) {
	        var items = data.length;
	        var sum = 0;
	        var progressBars = {
	            3: { id: "#pgError", flag: false },
	            4: { id: "#pgWarning", flag: false },
	            5: { id: "#pgNotice", flag: false },
	            6: { id: "#pgInfo", flag: false },
	            7: { id: "#pgDebug", flag: false }
	        };
	
	        for (var x = 0; x < items; x++) {
	            var key = parseInt(data[x][0], 10);
	            if (progressBars[key]) {
	                sum += data[x][1];
	            }
	        }
	
	        for (var x = 0; x < items; x++) {
	            var key = parseInt(data[x][0], 10);
	            if (progressBars[key]) {
	                $(progressBars[key].id).css('width', ((data[x][1] / sum) * 100) + "%");
	                progressBars[key].flag = true;
	            }
	        }
	
	        for (var key in progressBars) {
	            if (!progressBars[key].flag) {
	                $(progressBars[key].id).css('width', "0%");
	            }
	        }
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
		return value;
	}

	function LargeMessageFormat(value)
	{
		return "<span class='largemessage'>" + value + "</span>";
	}

	function FacilityFormat(value)
	{
		// DB drivers may return Facility as either a number (0..23) or a string ("0".."23").
		// Normalize so formatting works reliably either way.
		var v = parseInt(value, 10);
		switch(v)
		{
			case 0: { return "KERNEL-MESSAGE"; }
			case 1: { return "USER-MESSAGE"; }
			case 2: { return "MAIL-SYSTEM"; }
			case 3: { return "SECURITY-DAEMON"; }
			case 4: { return "AUTH-MESSAGE"; }
			case 5: { return "SYSLOGD"; }
			case 6: { return "PRINTER"; }
			case 7: { return "NETWORK"; }
			case 8: { return "UUCP"; }
			case 9: { return "CRON"; }
			case 10: { return "AUTH-MESSAGE-10"; }
			case 11: { return "FTP"; }
			case 12: { return "NTP"; }
			case 13: { return "LOG-AUDIT"; }
			case 14: { return "LOG-ALERT"; }
			case 15: { return "CLOCK-DAEMON"; }
			case 16: { return "LOCAL0"; }
			case 17: { return "LOCAL1"; }
			case 18: { return "LOCAL2"; }
			case 19: { return "LOCAL3"; }
			case 20: { return "LOCAL4"; }
			case 21: { return "LOCAL5"; }
			case 22: { return "LOCAL6"; }
			case 23: { return "LOCAL7"; }
		}
		return value;
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
 
<div class="app-shell">
<?php include __DIR__ . '/includes/partials/navbar-main.php'; ?>

<?php if ($showOnboarding): ?>
	<div id="rsyslogOnboardingOverlay" class="rsyslog-onboarding-overlay" data-csrf="<?php echo htmlspecialchars($onboardingCsrf, ENT_QUOTES, 'UTF-8'); ?>">
		<button type="button" class="btn btn-sm btn-outline-light rsyslog-onboarding-skip" data-onboarding-skip="1" aria-label="Skip onboarding">Got it</button>
		<div class="rsyslog-onboarding-card shadow-lg" role="dialog" aria-modal="false">
			<div class="rsyslog-onboarding-topline">
				<div class="fw-semibold">How to use System Logs</div>
				<div id="rsyslogOnboardingStep" class="text-muted small"></div>
			</div>
			<h3 id="rsyslogOnboardingTitle" class="h5 mt-2 mb-2"></h3>
			<p id="rsyslogOnboardingText" class="text-muted mb-0 small"></p>
			<div class="rsyslog-onboarding-dots mt-3" id="rsyslogOnboardingDots" aria-hidden="true"></div>
		</div>
	</div>

	<script>
	(function(){
		var overlay = document.getElementById('rsyslogOnboardingOverlay');
		if (!overlay) return;
		var csrf = overlay.getAttribute('data-csrf') || '';

		var reduceMotion = false;
		try { reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch(e){}

		var steps = [
			{
				selector: '#txtSearch',
				title: 'Search instantly',
				text: 'Type a host, tag, or any snippet. Your results update as you type.'
			},
			{
				selector: '#chkLive',
				title: 'Live tail mode',
				text: 'Turn on Live to refresh the table every few seconds while you investigate.'
			},
			{
				selector: '#pgError',
				title: 'Filter by severity',
				text: 'Click a severity bar (like Error) to add a filter automatically.'
			},
			{
				selector: '#table-style tbody tr',
				title: 'Open full details',
				text: 'Click a row to expand the full message safely (click again to collapse).'
			}
		];

		var durations = reduceMotion ? 1600 : [4200, 3400, 3400, 4200];
		var stepIndex = 0;
		var completed = false;

		function clearHighlights(){
			var highlighted = document.querySelectorAll('.rsyslog-onboarding-highlight');
			highlighted.forEach(function(el){ el.classList.remove('rsyslog-onboarding-highlight'); });
		}

		function updateDots(){
			var dots = document.getElementById('rsyslogOnboardingDots');
			if (!dots) return;
			var html = '';
			for (var i = 0; i < steps.length; i++){
				var active = i === stepIndex;
				html += '<span class="rsyslog-onboarding-dot ' + (active ? 'active' : '') + '"></span>';
			}
			dots.innerHTML = html;
		}

		function setStepUI(){
			var title = document.getElementById('rsyslogOnboardingTitle');
			var text = document.getElementById('rsyslogOnboardingText');
			var step = document.getElementById('rsyslogOnboardingStep');
			if (title) title.textContent = steps[stepIndex].title;
			if (text) text.textContent = steps[stepIndex].text;
			if (step) step.textContent = (stepIndex + 1) + ' / ' + steps.length;
			updateDots();
		}

		function highlightStep(){
			clearHighlights();
			setStepUI();
			var target = null;
			try { target = document.querySelector(steps[stepIndex].selector); } catch(e){}
			if (!target && stepIndex === 3) {
				// Table rows are populated asynchronously; wait briefly.
				var deadline = Date.now() + 9000;
				var poll = setInterval(function(){
					try {
						target = document.querySelector(steps[stepIndex].selector);
					} catch(e){}
					if (target || Date.now() > deadline){
						clearInterval(poll);
						if (target) target.classList.add('rsyslog-onboarding-highlight');
					}
				}, 250);
				return;
			}
			if (target) target.classList.add('rsyslog-onboarding-highlight');
		}

		function completeOnboarding(){
			if (completed) return;
			completed = true;
			clearHighlights();
			var card = overlay.querySelector('.rsyslog-onboarding-card');
			if (card) card.classList.add('rsyslog-onboarding-hide');

			// Best-effort server update; then remove overlay.
			try {
				var body = new URLSearchParams();
				body.set('csrf_token', csrf);
				fetch('json/onboarding-complete.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body
				}).catch(function(){});
			} catch(e) {}

			setTimeout(function(){
				if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
			}, reduceMotion ? 0 : 260);
		}

		var skipBtn = overlay.querySelector('[data-onboarding-skip="1"]');
		if (skipBtn){
			skipBtn.addEventListener('click', function(){
				completeOnboarding();
			});
		}

		// Auto-run without blocking normal clicks.
		(function run(){
			if (stepIndex >= steps.length) {
				completeOnboarding();
				return;
			}
			highlightStep();
			var dur = reduceMotion ? 1600 : durations[stepIndex];
			setTimeout(function(){
				stepIndex++;
				run();
			}, dur);
		})();
	})();
	</script>
<?php endif; ?>

<div class="app-home-hero mb-3" aria-label="System logs help">
	<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
		<div class="d-flex align-items-center gap-3">
			<div class="app-home-hero-icon">
				<i class="bi bi-journal-text" aria-hidden="true"></i>
			</div>
			<div>
				<div class="fw-semibold app-home-hero-title">System Logs</div>
				<div class="text-muted small">
					Search and tail syslog events with live updates. Click severity bars to filter, then click a row to expand full message.
				</div>
			</div>
		</div>

		<?php if ($devMode): ?>
			<div class="d-flex flex-wrap gap-2 justify-content-md-end">
				<button type="button" class="btn btn-sm btn-outline-secondary app-demoQuery" data-demo-query='"Severity"="ERROR" ' title="Filter demo severity">
					<i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Error only
				</button>
				<button type="button" class="btn btn-sm btn-outline-secondary app-demoQuery" data-demo-query='"Severity"="WARNING" ' title="Filter demo severity">
					<i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>Warnings
				</button>
			</div>
		<?php endif; ?>
	</div>
</div>

<div id="debugmessages"></div>

<div class="progress app-severity-strip mb-2" style="height: 22px">
  <div id="pgDebug" class="progress-bar progress-bar-striped bg-primary" style="width: 0%" title="Debug">
    <span class="visually-hidden">Debug</span>
  </div>
  <div id="pgInfo" class="progress-bar progress-bar-striped bg-info" style="width: 0%" title="Information">
    <span class="visually-hidden">Information</span>
  </div>
  <div id="pgNotice" class="progress-bar progress-bar-striped bg-success" style="width: 0%" title="Notice">
    <span class="visually-hidden">Notice</span>
  </div>
  <div id="pgWarning" class="progress-bar progress-bar-striped bg-warning" style="width: 0%" title="Warning">
    <span class="visually-hidden">Warning</span>
  </div>
  <div id="pgError" class="progress-bar progress-bar-striped bg-danger" style="width: 0%" title="Error">
    <span class="visually-hidden">Error</span>
  </div>
</div>

<!-- Modal (Bootstrap 5; reserved for future event details) -->
<div class="modal fade" id="mdEventDetails" tabindex="-1" aria-labelledby="mdEventDetailsLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title fs-5" id="mdEventDetailsLabel">Event details</h4>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-0">No event selected.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

  <div class="app-table-wrap">
  <table id="table-style" class="table table-sm small-table table-striped events-log-table" data-toggle="table" data-url="json/events.php" data-pagination="true" data-page-size="100" data-side-pagination="client" data-sortable="true">
	<thead> 
		<tr>
			<th data-field="ID" data-visible="false" data-formatter="idFormat" data-sortable="true">ID</th>
			<th data-field="Priority" data-formatter="SeverityFormat" data-sortable="true" data-align="center" data-width="110">Severity</th>
			<th data-field="DeviceReportedTime" data-sortable="true" data-width="154">Time</th>
			<th data-field="Facility" data-formatter="FacilityFormat" data-sortable="true" data-width="132">Facility</th>
			<th data-field="FromHost" data-sortable="true" data-width="120">Host</th>
			<th data-field="SysLogTag" data-sortable="true" data-width="120">Tag</th>
			<th data-field="processid" data-visible="false">PID</th>
			<th data-field="Messagetype" data-formatter="MessagetypeFormat" data-sortable="true" data-align="center" data-width="96">Type</th>
			<th data-field="SmallMessage" data-formatter="MessageFormat" data-sortable="true">Message</th>
			<th data-field="Message" data-visible="false" data-formatter="LargeMessageFormat">Full message</th>
		</tr>
	</thead>
  </table>
  </div>
  
</div>
<footer class="footer">
    <div class="container">
	</div>
</footer>
  </body>
</html>

