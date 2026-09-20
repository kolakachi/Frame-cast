/* Capture on the marketing origin and carry a stable visit ID into the app.
 * The handoff sets the app cookie; both requests count as the same arrival. */
(function () {
  'use strict';

  var KEY = 'wyv_aff';
  var WINDOW_DAYS = 90;
  var APP_HOST = 'app.wyvstudio.com';

  function read() {
    try {
      var raw = window.localStorage.getItem(KEY);
      if (!raw) return null;
      var saved = JSON.parse(raw);
      // Expire in step with the server's attribution window, so a link is not
      // still being decorated long after it stopped earning.
      if (!saved.code || Date.now() - saved.at > WINDOW_DAYS * 864e5) {
        window.localStorage.removeItem(KEY);
        return null;
      }
      return saved;
    } catch (e) {
      return null; // private window, or storage disabled
    }
  }

  function write(saved) {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(saved));
    } catch (e) {
      /* nothing to do: the link decoration below still works for this visit */
    }
  }

  // ?ref= is what affiliates are given; ?aff= is accepted too so a link copied
  // from the app's own URL keeps working.
  var params = new URLSearchParams(window.location.search);
  var incoming = (params.get('ref') || params.get('aff') || '').trim();
  if (incoming && /^[A-Za-z0-9_-]{1,32}$/.test(incoming)) {
    write({ code: incoming, at: Date.now(), event_id: crypto.randomUUID(), pending: true });
  }

  var saved = read();
  // Keep capture working when browser storage is unavailable.
  if (incoming && /^[A-Za-z0-9_-]{1,32}$/.test(incoming) && (!saved || saved.code !== incoming)) {
    saved = { code: incoming, at: Date.now(), event_id: crypto.randomUUID(), pending: true };
  }
  if (!saved) return;
  var code = saved.code;
  var attempts = 0;
  function capture() {
    if (!saved.pending || attempts >= 3) return;
    attempts++;
    fetch('/api/v1/affiliate/click', {
      method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ code: code, event_id: saved.event_id, landing_path: location.pathname.slice(0, 255) })
    }).then(function (response) {
      if (!response.ok) throw new Error('Tracking unavailable');
      return response.json();
    }).then(function () {
      saved.pending = false;
      write(saved);
    }).catch(function () { setTimeout(capture, attempts * 1500); });
  }
  capture();
  window.addEventListener('online', function () { attempts = 0; capture(); });

  function decorate(anchor) {
    if (!anchor || !anchor.href || anchor.dataset.wyvAff === '1') return;
    var url;
    try {
      url = new URL(anchor.href, window.location.href);
    } catch (e) {
      return;
    }
    if (url.hostname !== APP_HOST) return;
    // Never overwrite a code already on the link — a hand-built campaign URL
    // is more deliberate than a remembered one.
    if (!url.searchParams.has('aff')) {
      url.searchParams.set('aff', code);
      if (saved.event_id) url.searchParams.set('aff_visit', saved.event_id);
      anchor.href = url.toString();
    }
    anchor.dataset.wyvAff = '1';
  }

  function decorateAll() {
    var links = document.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) decorate(links[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', decorateAll);
  } else {
    decorateAll();
  }

  // Pricing cards and modals build their links after load, so a one-off pass
  // would miss exactly the links that lead to checkout.
  if (window.MutationObserver) {
    new MutationObserver(function () { decorateAll(); })
      .observe(document.documentElement, { childList: true, subtree: true });
  }

  // Refresh links at click time as well as when they are inserted.
  document.addEventListener('click', function (event) {
    var anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
    if (anchor) decorate(anchor);
  }, true);
})();
