/*
 * Affiliate capture for the marketing site.
 *
 * The app lives on a different host, so nothing here can set a cookie the app
 * will read: it would be third-party in that context and blocked by default in
 * Safari and Firefox. The API cannot be called from here either — CORS admits
 * only app.wyvstudio.com.
 *
 * So the code travels the only way that crosses hosts reliably: in the URL.
 * It is remembered locally the moment someone arrives, and then added to every
 * link pointing at the app, including ones added to the page later. The app
 * records it on arrival, where the request is same-origin and the cookie
 * sticks.
 *
 * Last click wins, matching the server, and matching what affiliates expect.
 */
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
      return saved.code;
    } catch (e) {
      return null; // private window, or storage disabled
    }
  }

  function write(code) {
    try {
      window.localStorage.setItem(KEY, JSON.stringify({ code: code, at: Date.now() }));
    } catch (e) {
      /* nothing to do: the link decoration below still works for this visit */
    }
  }

  // ?ref= is what affiliates are given; ?aff= is accepted too so a link copied
  // from the app's own URL keeps working.
  var params = new URLSearchParams(window.location.search);
  var incoming = (params.get('ref') || params.get('aff') || '').trim();
  if (incoming && /^[A-Za-z0-9_-]{1,32}$/.test(incoming)) {
    write(incoming);
  }

  var code = incoming || read();
  if (!code) return;

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

  // Anything that navigates without going through an <a> — a button handler,
  // say — still gets the code appended at the last moment.
  document.addEventListener('click', function (event) {
    var anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
    if (anchor) decorate(anchor);
  }, true);
})();
