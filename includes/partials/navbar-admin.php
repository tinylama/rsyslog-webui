<?php
$brand = htmlspecialchars((string) ($site_name ?? 'System Logs'), ENT_QUOTES, 'UTF-8');
$who = isset($displayName) && is_string($displayName) && $displayName !== ''
	? htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8')
	: '';
$active = $navAdminActive ?? 'users';
?>
<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom mb-3 app-navbar" aria-label="Admin">
  <div class="container-fluid px-0 px-sm-2">
    <a class="navbar-brand" href="../" title="Back to logs"><?php echo $brand; ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="adminNavbar">
      <div class="d-flex w-100 align-items-center">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0 align-items-lg-center gap-lg-1">
        <li class="nav-item">
          <a class="nav-link<?php echo $active === 'users' ? ' active' : ''; ?>" href="users.php" <?php echo $active === 'users' ? 'aria-current="page"' : ''; ?>><i class="bi bi-people me-1" aria-hidden="true"></i>Directory</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo $active === 'entra' ? ' active' : ''; ?>" href="settings-entra.php" <?php echo $active === 'entra' ? 'aria-current="page"' : ''; ?>><i class="bi bi-microsoft me-1" aria-hidden="true"></i>Entra settings</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo $active === 'https' ? ' active' : ''; ?>" href="settings-https.php" <?php echo $active === 'https' ? 'aria-current="page"' : ''; ?>><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>HTTPS whitelist</a>
        </li>
        <li class="nav-item">
          <a class="nav-link<?php echo $active === 'maintenance' ? ' active' : ''; ?>" href="maintenance.php" <?php echo $active === 'maintenance' ? 'aria-current="page"' : ''; ?>><i class="bi bi-tools me-1" aria-hidden="true"></i>Maintenance</a>
        </li>
        <?php if (!empty($auth_enabled) && $who !== ''): ?>
        <li class="nav-item">
          <a class="nav-link" href="../account/security.php"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Account</a>
        </li>
        <?php endif; ?>
        </ul>

        <div class="ms-auto d-flex align-items-center gap-2">
          <div class="form-check m-0">
            <input class="form-check-input" type="checkbox" id="darkSwitch" aria-label="Dark mode">
            <label class="form-check-label small" for="darkSwitch">Dark</label>
          </div>

          <?php if (!empty($auth_enabled) && $who !== ''): ?>
          <div class="dropdown">
            <a class="dropdown-toggle d-flex align-items-center gap-2 text-decoration-none" href="#" id="adminUserMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-person-circle fs-5" aria-hidden="true"></i>
              <span class="text-truncate" style="max-width: 10rem">
                <?php echo $who; ?>
              </span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="adminUserMenu">
              <li>
                <a class="dropdown-item" href="../account/security.php">
                  <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Account security
                </a>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item" href="../logout.php">
                  <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out
                </a>
              </li>
            </ul>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</nav>
