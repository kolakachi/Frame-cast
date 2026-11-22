/* Answer pages: the phone menu, the light/dark switch and click-to-play videos, as on the homepage. */
(function () {
  var bar = document.querySelector('.nav-bar'), btn = document.getElementById('nav-menu-btn'), menu = document.getElementById('nav-menu');
  if (bar && btn && menu) {
    var set = function (open) { bar.classList.toggle('open', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false'); btn.setAttribute('aria-label', open ? 'Close menu' : 'Menu'); };
    btn.addEventListener('click', function () { set(!bar.classList.contains('open')); });
    menu.addEventListener('click', function (e) { if (e.target.closest('a')) set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
  }

  var root = document.documentElement, tt = document.getElementById('theme-toggle');
  if (tt) {
    var label = function () {
      var light = root.getAttribute('data-theme') === 'light';
      tt.setAttribute('aria-checked', light ? 'true' : 'false');
      tt.title = 'Switch to ' + (light ? 'dark' : 'light') + ' mode';
    };
    tt.addEventListener('click', function () {
      var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('wyv_theme', next); } catch (e) {}
      label();
    });
    label();
  }

  var boxes = document.querySelectorAll('.vid');
  boxes.forEach(function (box) {
    var v = box.querySelector('video'), b = box.querySelector('.play');
    if (!v || !b) return;
    var text = b.getAttribute('aria-label');
    function toggle() {
      if (v.paused) {
        boxes.forEach(function (o) { var ov = o.querySelector('video'); if (ov && ov !== v) ov.pause(); });
        v.muted = false;
        var p = v.play();
        if (p) p.catch(function () { v.muted = true; v.play().catch(function () {}); });
      } else v.pause();
    }
    b.addEventListener('click', toggle);
    v.addEventListener('click', toggle);
    v.addEventListener('play', function () { box.classList.add('is-playing'); b.setAttribute('aria-label', text.replace('Play', 'Pause')); });
    v.addEventListener('pause', function () { box.classList.remove('is-playing'); b.setAttribute('aria-label', text); });
  });
})();
