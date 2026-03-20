/**
 * Dark mode toggle: runs after DOM is ready so #darkSwitch exists.
 * Persists choice in localStorage under key 'darkSwitch'.
 *
 * Uses Bootstrap 5.3 color mode (data-bs-theme on <html>) so tables, forms,
 * and other components pick up dark variables. Keeps data-theme on <body>
 * for legacy css/dark-mode.css hooks.
 */
window.addEventListener('load', () => {
  const darkSwitch = document.getElementById('darkSwitch');
  if (!darkSwitch) {
    return;
  }

  function applyTheme(isDark) {
    const root = document.documentElement;
    if (isDark) {
      root.setAttribute('data-bs-theme', 'dark');
      document.body.setAttribute('data-theme', 'dark');
    } else {
      root.setAttribute('data-bs-theme', 'light');
      document.body.removeAttribute('data-theme');
    }
  }

  function initTheme() {
    const darkThemeSelected =
      localStorage.getItem('darkSwitch') !== null &&
      localStorage.getItem('darkSwitch') === 'dark';
    darkSwitch.checked = darkThemeSelected;
    applyTheme(darkThemeSelected);
  }

  function resetTheme() {
    if (darkSwitch.checked) {
      localStorage.setItem('darkSwitch', 'dark');
    } else {
      localStorage.removeItem('darkSwitch');
    }
    applyTheme(darkSwitch.checked);
  }

  initTheme();
  darkSwitch.addEventListener('change', resetTheme);
});
