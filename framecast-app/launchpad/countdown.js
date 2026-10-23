(function () {
  'use strict';

  var targetSelector = document.body.getAttribute('data-launch-countdown-target');
  var destination = targetSelector && document.querySelector(targetSelector);
  var hero = document.querySelector('[data-launch-countdown-hero]');
  if (!destination || !hero || document.getElementById('sticky-countdown-bar')) return;

  var bar = document.createElement('aside');
  bar.id = 'sticky-countdown-bar';
  bar.className = 'launch-countdown';
  bar.setAttribute('aria-label', 'Limited time launch offer countdown');
  bar.innerHTML = '<div class="launch-countdown__inner">' +
    '<div class="launch-countdown__offer"><span class="launch-countdown__dot" aria-hidden="true"></span>' +
    '<p class="launch-countdown__label"><span class="launch-countdown__long-label">EarlyBirds Discount Ends &amp;<br> </span>Ends in:</p></div>' +
    '<div class="launch-countdown__clock" role="timer" aria-live="off" aria-label="Time remaining">' +
    ['Days', 'Hours', 'Mins', 'Secs'].map(function (label, i) {
      return (i ? '<span class="launch-countdown__separator" aria-hidden="true">:</span>' : '') +
        '<div class="launch-countdown__unit"><span class="launch-countdown__unit-label">' + label.toUpperCase() + '</span>' +
        '<span class="launch-countdown__value" id="top-cd-' + label.toLowerCase() + '">00</span></div>';
    }).join('') + '</div>' +
    '<div class="launch-countdown__actions"><button class="launch-countdown__theme" type="button" aria-label="Switch theme">' +
    '<svg class="launch-countdown__sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>' +
    '<svg class="launch-countdown__moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>' +
    '<a class="launch-countdown__buy">Buy Now!</a></div></div>';
  bar.querySelector('a').setAttribute('href', targetSelector);
  document.body.prepend(bar);
  destination.classList.add('launch-countdown-destination');

  var headers = document.querySelectorAll('[data-launch-countdown-header]');
  function updateVisibility() {
    var visible = hero.getBoundingClientRect().bottom < 120;
    bar.classList.toggle('is-visible', visible);
    bar.inert = !visible;
    headers.forEach(function (header) {
      header.classList.toggle('launch-countdown-header-hidden', visible);
      header.classList.toggle('header-scrolled', window.scrollY > 10);
    });
  }
  window.addEventListener('scroll', updateVisibility, { passive: true });
  window.addEventListener('resize', updateVisibility);
  window.addEventListener('pageshow', updateVisibility);
  if (typeof ResizeObserver !== 'undefined') new ResizeObserver(updateVisibility).observe(hero);
  updateVisibility();

  // Keep the approved rolling deadline across every page in the funnel.
  var storageKey = 'wyv_launch_countdown_v2';
  var endTime;
  try { endTime = Number(localStorage.getItem(storageKey)); } catch (e) { }
  function startTimer(seconds) {
    endTime = Date.now() + seconds * 1000;
    try { localStorage.setItem(storageKey, String(endTime)); } catch (e) { }
  }
  if (!Number.isFinite(endTime) || endTime <= Date.now()) startTimer(1 * 3600 + 36 * 60 + 43);

  var values = bar.querySelectorAll('.launch-countdown__value');
  function updateCountdown() {
    var diff = Math.max(0, Math.floor((endTime - Date.now()) / 1000));
    var units = [Math.floor(diff / 86400), Math.floor(diff % 86400 / 3600), Math.floor(diff % 3600 / 60), diff % 60];
    values.forEach(function (element, i) { element.textContent = String(units[i]).padStart(2, '0'); });
    if (diff <= 0) startTimer(2 * 3600);
  }
  window.addEventListener('storage', function (event) {
    if (event.key === storageKey && Number.isFinite(Number(event.newValue)) && Number(event.newValue) > Date.now()) {
      endTime = Number(event.newValue);
      updateCountdown();
    }
  });
  updateCountdown();
  setInterval(updateCountdown, 1000);

  var root = document.documentElement;
  var themeButton = bar.querySelector('button');
  function syncThemeLabel() {
    themeButton.setAttribute('aria-label', root.classList.contains('dark') ? 'Switch to light mode' : 'Switch to dark mode');
  }
  themeButton.addEventListener('click', function () {
    if (typeof window.wyvToggleTheme === 'function') {
      window.wyvToggleTheme();
    } else {
      var dark = !root.classList.contains('dark');
      var theme = dark ? 'dark' : 'light';
      root.classList.toggle('dark', dark);
      root.setAttribute('data-theme', theme);
      document.querySelectorAll('.theme-icon-sun, #theme-icon-sun').forEach(function (icon) { icon.classList.toggle('hidden', !dark); });
      document.querySelectorAll('.theme-icon-moon, #theme-icon-moon').forEach(function (icon) { icon.classList.toggle('hidden', dark); });
      var mainToggle = document.getElementById('theme-toggle');
      if (mainToggle) mainToggle.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
      try {
        localStorage.setItem('wyv-theme', theme);
        localStorage.setItem('wyv_theme', theme);
      } catch (e) { }
    }
    syncThemeLabel();
  });
  new MutationObserver(syncThemeLabel).observe(root, { attributes: true, attributeFilter: ['class'] });
  syncThemeLabel();
})();
