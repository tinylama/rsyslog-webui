<?php
/**
 * Main app navbar (logs view). Expects: $site_name, $isAdmin, $auth_enabled, $displayName (string).
 */
$brand = htmlspecialchars((string) ($site_name ?? 'System Logs'), ENT_QUOTES, 'UTF-8');
$who = isset($displayName) && is_string($displayName) && $displayName !== ''
	? htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8')
	: '';
$authOn = !empty($auth_enabled);
?>
<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-2 app-navbar" role="navigation">
  <div class="container-fluid px-0 px-sm-2 align-items-lg-center">
    <a class="navbar-brand flex-shrink-0" href="./" title="Home"><?php echo $brand; ?></a>
    <button class="navbar-toggler flex-shrink-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNavbar">
      <form class="app-toolbar app-toolbar-main flex-lg-grow-1 order-lg-1 my-2 my-lg-0" role="search" id="mainSearchForm" action="#" method="get">
        <input id="txtSearch" type="search" class="form-control" placeholder="Search message, host, tag…" autocomplete="off" enterkeyhint="search" aria-label="Search logs">
        <div class="app-toolbar-actions app-toolbar-actions-icons">
          <input type="checkbox" class="btn-check" id="darkSwitch" autocomplete="off" aria-label="Dark mode">
          <label class="btn btn-sm btn-outline-secondary" for="darkSwitch" title="Dark mode"><i class="bi bi-moon-stars" aria-hidden="true"></i><span class="visually-hidden"> Dark</span></label>
          <input class="btn-check" type="checkbox" id="chkLive" checked autocomplete="off" aria-label="Live tail">
          <label class="btn btn-sm btn-outline-secondary" for="chkLive" title="Live tail"><i class="bi bi-broadcast" aria-hidden="true"></i><span class="visually-hidden"> Live</span></label>
          <button id="cmdSearch" type="submit" class="btn btn-sm btn-outline-secondary" title="Reload from server"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i><span class="visually-hidden"> Reload</span></button>
          <button id="cmdReset" type="button" class="btn btn-sm btn-outline-secondary" title="Clear search"><i class="bi bi-x-lg" aria-hidden="true"></i><span class="visually-hidden"> Reset</span></button>
        </div>
      </form>
      <?php if ($authOn && $who !== ''): ?>
      <ul class="navbar-nav order-lg-2 ms-lg-2 flex-shrink-0">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-1 py-lg-2" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-circle fs-5" aria-hidden="true"></i>
            <span class="text-truncate" style="max-width: 10rem"><?php echo $who; ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="userMenu">
            <li><a class="dropdown-item" href="account/security.php"><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Account security</a></li>
            <?php if (!empty($isAdmin)): ?>
            <li><a class="dropdown-item" href="admin/users.php"><i class="bi bi-people me-2" aria-hidden="true"></i>Admin</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</a></li>
          </ul>
        </li>
      </ul>
      <?php elseif ($authOn): ?>
      <ul class="navbar-nav order-lg-2 ms-lg-2">
        <li class="nav-item"><a class="nav-link" href="login.php">Sign in</a></li>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</nav>
