<?php
$docsActive = $docsActive ?? 'index';
?>
<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-3 app-navbar" aria-label="Documentation">
	<div class="container-fluid px-0 px-sm-2">
		<a class="navbar-brand" href="../">System Logs</a>
		<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#docsNavbar" aria-controls="docsNavbar" aria-expanded="false" aria-label="Toggle docs navigation">
			<span class="navbar-toggler-icon"></span>
		</button>
		<div class="collapse navbar-collapse" id="docsNavbar">
			<ul class="navbar-nav me-auto mb-2 mb-lg-0">
				<li class="nav-item"><a class="nav-link<?php echo $docsActive === 'index' ? ' active' : ''; ?>" href="index.php">Overview</a></li>
				<li class="nav-item"><a class="nav-link<?php echo $docsActive === 'installation' ? ' active' : ''; ?>" href="installation.php">Installation</a></li>
				<li class="nav-item"><a class="nav-link<?php echo $docsActive === 'authentication' ? ' active' : ''; ?>" href="authentication.php">Authentication</a></li>
				<li class="nav-item"><a class="nav-link<?php echo $docsActive === 'deployment' ? ' active' : ''; ?>" href="deployment.php">Deployment</a></li>
				<li class="nav-item"><a class="nav-link<?php echo $docsActive === 'testing' ? ' active' : ''; ?>" href="testing.php">Testing</a></li>
			</ul>
			<a class="btn btn-sm btn-outline-secondary" href="../"><i class="bi bi-arrow-left me-1"></i>Back to app</a>
		</div>
	</div>
</nav>
